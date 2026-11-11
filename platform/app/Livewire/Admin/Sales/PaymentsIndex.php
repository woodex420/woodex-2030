<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Sales;

use App\Domain\Accounts\Exceptions\JournalPostingFailed;
use App\Domain\Crm\Models\Contact;
use App\Domain\Sales\Actions\RecordPayment;
use App\Domain\Sales\Actions\RejectPayment;
use App\Domain\Sales\Actions\VerifyPayment;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Enums\PaymentStatus;
use App\Domain\Sales\Models\BankAccount;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Services\ReceiptPdf;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Settings\SalesSettings;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\WithFileUploads;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Money received (INV-005/006/007): every payment with its proof, what it settled and its receipt. A proof a client
 * uploaded waits here for the accountant, who confirms it (which writes the receipt) or sends it back with a reason.
 */
#[Layout('layouts::admin')]
#[Title('Payments')]
class PaymentsIndex extends Component
{
    use InteractsWithAdmin, WithFileUploads, WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    public bool $showForm = false;

    public string $contactSearch = '';

    public ?int $contactId = null;

    public ?int $invoiceId = null;

    public string $amount = '';

    public string $receivedOn = '';

    public string $method = 'bank_transfer';

    public string $reference = '';

    public ?int $bankAccountId = null;

    public string $notes = '';

    public bool $showReject = false;

    public int $rejectId = 0;

    public string $rejectReason = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Payment::class);
        $this->receivedOn = now()->toDateString();
        $this->bankAccountId = BankAccount::query()->where('is_active', true)->orderByDesc('is_default')->value('id');
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function updatedContactSearch(): void
    {
        $this->contactId = null;
    }

    public function pickContact(int $id): void
    {
        $this->contactId = $id;
        $this->contactSearch = (string) Contact::query()->find($id)?->name;
        $this->invoiceId = null;
    }

    public function save(RecordPayment $record): void
    {
        $this->authorize('create', Payment::class);

        $validated = $this->validate([
            'contactId' => ['required', 'integer', 'exists:contacts,id'],
            'invoiceId' => ['nullable', 'integer', 'exists:invoices,id'],
            'amount' => ['required', 'numeric', 'min:1', 'regex:/^\d{1,14}(?:\.\d{1,2})?$/'],
            'receivedOn' => ['required', 'date'],
            'method' => ['required', 'string', 'in:'.implode(',', array_keys(PaymentMethod::options()))],
            'reference' => ['nullable', 'string', 'max:120'],
            'bankAccountId' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'contactId' => 'client',
            'receivedOn' => 'date received',
        ]);

        try {
            $record->handle([
                'contact_id' => $this->contactId,
                'invoice_id' => $this->invoiceId,
                'amount_minor' => Money::ofRupees($this->amount)->minor,
                'received_on' => $this->receivedOn,
                'method' => $this->method,
                'reference' => $this->reference ?: null,
                'bank_account_id' => $this->bankAccountId,
                'notes' => $this->notes ?: null,
            ], $this->user());
        } catch (JournalPostingFailed $e) {
            $this->addError('amount', $e->getMessage());

            return;
        }

        $this->reset(['amount', 'reference', 'notes', 'invoiceId', 'contactId', 'contactSearch']);
        $this->showForm = false;
        $this->toast('Payment recorded and receipt issued.');
    }

    public function openReject(int $id): void
    {
        $this->authorize('verify', Payment::query()->findOrFail($id));
        $this->rejectId = $id;
        $this->rejectReason = '';
        $this->showReject = true;
    }

    public function reject(RejectPayment $rejects): void
    {
        $this->validate(['rejectReason' => ['required', 'string', 'min:5', 'max:500']], [], ['rejectReason' => 'reason']);

        $payment = Payment::query()->findOrFail($this->rejectId);
        $this->authorize('verify', $payment);
        $rejects->handle($payment, $this->rejectReason, $this->user());

        $this->showReject = false;
        $this->toast('Payment sent back to the client.');
    }

    public function verify(int $id, VerifyPayment $verify): void
    {
        $this->authorize('verify', Payment::query()->findOrFail($id));
        $payment = $verify->handle(Payment::query()->findOrFail($id), $this->user());

        $this->toast('Payment confirmed · receipt '.$payment->receipt_number.'.');
    }

    public function receipt(int $id): StreamedResponse
    {
        $payment = Payment::query()->findOrFail($id);
        $this->authorize('view', $payment);
        abort_if($payment->receipt_pdf_path === null || ! Storage::disk('local')->exists($payment->receipt_pdf_path), 404);

        return Storage::disk('local')->download($payment->receipt_pdf_path, ReceiptPdf::filename($payment), ['Content-Type' => 'application/pdf']);
    }

    public function render(): View
    {
        $payments = Payment::query()
            ->with(['contact:id,name', 'invoice:id,number', 'bankAccount:id,title', 'allocations.invoice:id,number', 'verifier:id,name'])
            ->when($this->search !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('reference', 'like', '%'.$this->search.'%')
                ->orWhere('receipt_number', 'like', '%'.$this->search.'%')
                ->orWhereHas('contact', fn (Builder $c) => $c->where('name', 'like', '%'.$this->search.'%'))
                ->orWhereHas('invoice', fn (Builder $c) => $c->where('number', 'like', '%'.$this->search.'%'))))
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->orderByRaw("case status when 'pending_verification' then 0 else 1 end")
            ->orderByDesc('received_on')
            ->latest('id')
            ->paginate(25);

        $counts = Payment::query()->toBase()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');

        return view('livewire.admin.sales.payments-index', [
            'payments' => $payments,
            'counts' => $counts,
            'total' => (int) $counts->sum(),
            'pending' => (int) ($counts[PaymentStatus::PendingVerification->value] ?? 0),
            'received' => (int) Payment::query()->where('status', PaymentStatus::Verified->value)->sum('amount_minor'),
            'chips' => [PaymentStatus::PendingVerification, PaymentStatus::Verified, PaymentStatus::Rejected],
            'methods' => PaymentMethod::options(),
            'accounts' => BankAccount::query()->where('is_active', true)->orderByDesc('is_default')->get(['id', 'title', 'bank_name']),
            'contacts' => $this->contactSearch !== '' && $this->contactId === null
                ? Contact::query()->where('name', 'like', '%'.$this->contactSearch.'%')->orderBy('name')->limit(8)->get(['id', 'name', 'phone'])
                : collect(),
            'openInvoices' => $this->contactId === null ? collect() : Invoice::query()
                ->where('contact_id', $this->contactId)
                ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
                ->where('balance_minor', '>', 0)
                ->orderBy('due_date')
                ->get(['id', 'number', 'total_minor', 'balance_minor']),
            'lakh' => app(SalesSettings::class)->lakh_grouping,
        ]);
    }
}
