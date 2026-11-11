<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Accounts;

use App\Domain\Accounts\Actions\PayBill;
use App\Domain\Accounts\Actions\ReceiveBill;
use App\Domain\Accounts\Actions\SaveBill;
use App\Domain\Accounts\Actions\VoidBill;
use App\Domain\Accounts\Enums\BillStatus;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\Bill;
use App\Domain\Accounts\Models\BillPayment;
use App\Domain\Accounts\Models\Vendor;
use App\Domain\Projects\Models\Project;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Settings\SalesSettings;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Received invoices — the vendor's bill to the studio (slice I). The page the owner's screenshot asked for:
 * number, vendor, dates, value and paid status in one list, with the ledger behind it.
 *
 * A bill is entered as a draft, then posted when it arrives: posting books Dr the account on every line (and
 * input tax when charged) / Cr Accounts payable 2010. Payments clear the payable and are posted one by one.
 * Posted bills are immutable — a mistake is voided with a reason and the journal is reversed, never edited away.
 */
#[Layout('layouts::admin')]
#[Title('Received invoices')]
class BillsIndex extends Component
{
    use InteractsWithAdmin;

    public string $statusFilter = 'open';

    public ?int $vendorFilter = null;

    #[Url(as: 'billProject')]
    public ?int $projectFilter = null;

    public ?int $projectId = null;

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public ?int $vendorId = null;

    public string $number = '';

    public string $billDate = '';

    public string $dueDate = '';

    public string $taxRupees = '0';

    public string $notes = '';

    /** @var list<array{accountId: int|string, description: string, amountRupees: string}> */
    public array $lines = [];

    #[Locked]
    public ?int $payingId = null;

    #[Locked]
    public ?string $payRequestKey = null;

    public string $payAmount = '';

    public string $payMethod = 'bank_transfer';

    public string $payReference = '';

    public ?int $voidingId = null;

    public string $voidReason = '';

    public function mount(): void
    {
        $this->authorize('accounts.view');
        $this->resetForm();
    }

    public function openForm(?int $id = null): void
    {
        $this->authorize('accounts.manage');
        $this->resetValidation();
        $this->resetForm();

        if ($id === null) {
            $this->showForm = true;

            return;
        }

        $bill = Bill::query()->with('items')->findOrFail($id);

        if ($bill->status !== BillStatus::Draft) {
            $this->toast('A posted bill cannot be edited — void it and enter the correct one.', 'error');

            return;
        }

        $this->editingId = $bill->id;
        $this->vendorId = $bill->vendor_id;
        $this->projectId = $bill->project_id;
        $this->number = $bill->number;
        $this->billDate = $bill->bill_date->toDateString();
        $this->dueDate = $bill->due_date?->toDateString() ?? '';
        $this->taxRupees = Money::ofMinor($bill->tax_minor)->toDecimalString();
        $this->notes = (string) $bill->notes;
        $this->lines = $bill->items->map(fn ($item): array => [
            'accountId' => $item->account_id,
            'description' => $item->description,
            'amountRupees' => Money::ofMinor($item->amount_minor)->toDecimalString(),
        ])->values()->all();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->resetValidation();
    }

    public function addLine(): void
    {
        $this->lines[] = ['accountId' => '', 'description' => '', 'amountRupees' => ''];
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);

        if ($this->lines === []) {
            $this->addLine();
        }
    }

    /** Save as a draft — nothing reaches the books until it is received. */
    public function saveDraft(): void
    {
        $this->persist(BillStatus::Draft);
    }

    /** Save and book it: Dr the lines, Cr accounts payable. */
    public function saveReceived(): void
    {
        $this->persist(BillStatus::Received);
    }

    public function post(int $id): void
    {
        $this->authorize('accounts.manage');

        $bill = Bill::query()->findOrFail($id);

        if ($bill->status !== BillStatus::Draft) {
            $this->toast('That bill is already posted.', 'warning');

            return;
        }

        try {
            $posted = app(ReceiveBill::class)->handle($bill, $this->user());
            $entry = $posted->journalEntry()->firstOrFail();
        } catch (Throwable $exception) {
            $this->toast('Not posted — '.$exception->getMessage(), 'error');

            return;
        }

        $this->toast('Bill posted as '.$entry->entry_no.'.');
    }

    public function openPay(int $id): void
    {
        $this->authorize('accounts.manage');

        $bill = Bill::query()->findOrFail($id);

        if (! $bill->isOpen()) {
            $this->toast('That bill is not open for payment.', 'error');

            return;
        }

        $this->resetValidation();
        $this->payingId = $bill->id;
        $this->payRequestKey = (string) Str::uuid();
        $this->payAmount = Money::ofMinor($bill->balanceMinor())->toDecimalString();
        $this->payMethod = 'bank_transfer';
        $this->payReference = '';
    }

    public function closePay(): void
    {
        $this->payingId = null;
        $this->resetValidation();
    }

    public function pay(): void
    {
        $this->authorize('accounts.manage');

        $bill = Bill::query()->findOrFail((int) $this->payingId);

        $data = $this->validate([
            'payAmount' => ['required', 'numeric', 'gt:0'],
            'payMethod' => ['required', 'in:'.implode(',', PaymentMethod::values())],
            'payReference' => ['nullable', 'string', 'max:80'],
        ]);

        try {
            $payment = app(PayBill::class)->handle(
                $bill,
                Money::ofRupees((string) $data['payAmount'])->minor,
                PaymentMethod::from($data['payMethod']),
                $this->user(),
                $data['payReference'] !== '' ? $data['payReference'] : null,
                idempotencyKey: $this->payRequestKey,
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, (string) ($messages[0] ?? 'That payment was refused.'));
            }

            return;
        }

        $this->payingId = null;
        $this->toast('Paid '.$payment->amount()->format().' against '.$bill->number.' — the payable is cleared by that much.');
    }

    public function openVoid(int $id): void
    {
        $this->authorize('accounts.manage');

        $this->resetValidation();
        $this->voidingId = $id;
        $this->voidReason = '';
    }

    public function closeVoid(): void
    {
        $this->voidingId = null;
        $this->resetValidation();
    }

    public function voidBill(): void
    {
        $this->authorize('accounts.manage');

        $bill = Bill::query()->findOrFail((int) $this->voidingId);

        try {
            app(VoidBill::class)->handle($bill, $this->user(), $this->voidReason);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError('voidReason', (string) ($messages[0] ?? 'That bill cannot be voided.'));
            }

            return;
        }

        $this->voidingId = null;
        $this->toast($bill->number.' is void — its journal entry was reversed, not deleted.');
    }

    public function render(): View
    {
        $this->authorize('accounts.view');
        if ($this->projectFilter !== null) {
            $this->authorize('view', Project::withTrashed()->findOrFail($this->projectFilter));
        }
        $base = Bill::query()->when($this->projectFilter !== null, fn ($query) => $query->where('project_id', $this->projectFilter));
        $query = (clone $base)->with(['vendor', 'journalEntry', 'project']);

        if ($this->statusFilter === 'open') {
            $query->whereIn('status', [BillStatus::Received, BillStatus::PartiallyPaid]);
        } elseif ($this->statusFilter === 'paid') {
            $query->where('status', BillStatus::Paid);
        } elseif ($this->statusFilter === 'void') {
            $query->where('status', BillStatus::Void);
        } elseif ($this->statusFilter === 'draft') {
            $query->where('status', BillStatus::Draft);
        }

        if ($this->vendorFilter !== null) {
            $query->where('vendor_id', $this->vendorFilter);
        }

        $bills = $query->orderByDesc('bill_date')->orderByDesc('id')->limit(200)->get();

        $open = (clone $base)->whereIn('status', [BillStatus::Received, BillStatus::PartiallyPaid])->get();
        $overdue = $open->filter(fn (Bill $bill): bool => $bill->isOverdue());

        return view('livewire.admin.accounts.bills-index', [
            'bills' => $bills,
            'projects' => $this->user()->can('projects.view') ? Project::withTrashed()->orderBy('code')->get(['id', 'code', 'name', 'deleted_at']) : collect(),
            'vendors' => Vendor::query()->orderBy('name')->get(['id', 'name', 'is_active']),
            'accounts' => Account::query()
                ->whereIn('type', ['expense', 'asset'])
                ->where('is_group', false)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'type']),
            'methods' => PaymentMethod::options(),
            'outstanding' => (int) $open->sum(fn (Bill $bill): int => $bill->balanceMinor()),
            'overdueTotal' => (int) $overdue->sum(fn (Bill $bill): int => $bill->balanceMinor()),
            'paidThisMonth' => (int) BillPayment::query()
                ->when($this->projectFilter !== null, fn ($query) => $query->whereHas('bill', fn ($bill) => $bill->where('project_id', $this->projectFilter)))
                ->whereDate('paid_on', '>=', today()->startOfMonth())
                ->whereDate('paid_on', '<', today()->startOfMonth()->addMonth())->sum('amount_minor'),
            'canManage' => $this->user()->can('accounts.manage'),
            'lakh' => app(SalesSettings::class)->lakh_grouping,
        ]);
    }

    private function persist(BillStatus $status): void
    {
        $this->authorize('accounts.manage');

        $data = $this->validate([
            'vendorId' => ['required', 'integer', 'exists:vendors,id'],
            'projectId' => ['nullable', 'integer', 'exists:projects,id'],
            'number' => ['required', 'string', 'max:60'],
            'billDate' => ['required', 'date'],
            'dueDate' => ['nullable', 'date', 'after_or_equal:billDate'],
            'taxRupees' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.accountId' => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.amountRupees' => ['required', 'numeric', 'gt:0'],
        ], [
            'lines.*.accountId.required' => 'Every line needs an account.',
            'lines.*.description.required' => 'Every line needs a description.',
            'lines.*.amountRupees.gt' => 'Every line needs an amount.',
        ]);

        $taxMinor = Money::ofRupees((string) $data['taxRupees'])->minor;

        $items = array_map(function (array $line, int $index): array {
            $amount = Money::ofRupees((string) $line['amountRupees'])->minor;

            return [
                'account_id' => (int) $line['accountId'],
                'description' => trim((string) $line['description']),
                'amount_minor' => $amount,
                'position' => $index,
            ];
        }, array_values($data['lines']), array_keys(array_values($data['lines'])));

        $existing = $this->editingId !== null ? Bill::query()->findOrFail($this->editingId) : null;

        try {
            $bill = app(SaveBill::class)->handle([
                'number' => trim($data['number']),
                'project_id' => $data['projectId'],
                'vendor_id' => (int) $data['vendorId'],
                'bill_date' => $data['billDate'],
                'due_date' => $data['dueDate'] !== '' ? $data['dueDate'] : null,
                'tax_minor' => $taxMinor,
                'notes' => $this->blankToNull($data['notes']),
            ], $items, $this->user(), $status, $existing);
        } catch (ValidationException $exception) {
            $this->toast('Not saved — '.$exception->getMessage(), 'error');
            foreach ($exception->errors() as $field => $messages) {
                $mapped = ['project_id' => 'projectId', 'vendor_id' => 'vendorId', 'bill_date' => 'billDate', 'due_date' => 'dueDate', 'tax_minor' => 'taxRupees'];
                $this->addError($mapped[$field] ?? $field, (string) ($messages[0] ?? 'That bill was refused.'));
            }

            return;
        } catch (Throwable $exception) {
            $this->toast('Not saved — '.$exception->getMessage(), 'error');

            return;
        }

        $this->showForm = false;
        $this->editingId = null;
        $this->toast($status === BillStatus::Draft
            ? $bill->number.' saved as a draft — post it when the invoice arrives.'
            : $bill->number.' posted — the cost and the payable are in the books.');
    }

    private function resetForm(): void
    {
        $this->vendorId = null;
        $this->projectId = $this->user()->can('projects.view') ? $this->projectFilter : null;
        $this->number = '';
        $this->billDate = today()->toDateString();
        $this->dueDate = today()->addDays(15)->toDateString();
        $this->taxRupees = '0';
        $this->notes = '';
        $this->lines = [['accountId' => '', 'description' => '', 'amountRupees' => '']];
    }

    private function blankToNull(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value === '' || $value === null ? null : $value;
    }
}
