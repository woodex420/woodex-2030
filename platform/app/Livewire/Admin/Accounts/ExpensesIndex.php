<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Accounts;

use App\Domain\Accounts\Actions\RecordExpense;
use App\Domain\Accounts\Enums\ExpenseDirection;
use App\Domain\Accounts\Enums\PaidFrom;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\Expense;
use App\Domain\Accounts\Models\Vendor;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Settings\SalesSettings;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Money in and out of the books that is not a client invoice (slice I): a one-off expense, or income that did not
 * come from a quotation. The row and its journal are written together — an expense that is not posted does not
 * exist here, because a list of costs the books never saw is the exact gap this slice closes.
 *
 * `OtherIncome` reuses this screen with the direction flipped; the form, validation and posting are identical.
 */
#[Layout('layouts::admin')]
#[Title('Expenses')]
class ExpensesIndex extends Component
{
    use InteractsWithAdmin;

    public bool $showForm = false;

    public string $description = '';

    public string $amountRupees = '';

    public ?int $accountId = null;

    public ?int $vendorId = null;

    public string $spentOn = '';

    public string $paidFrom = 'cash';

    public string $reference = '';

    public string $notes = '';

    public string $filterFrom = '';

    public string $filterTo = '';

    public function mount(): void
    {
        $this->authorize('accounts.view');
        $this->spentOn = today()->toDateString();
        $this->filterFrom = today()->startOfYear()->toDateString();
        $this->filterTo = today()->toDateString();
        $this->paidFrom = $this->direction() === ExpenseDirection::Out ? 'cash' : 'bank';
    }

    public function openForm(): void
    {
        $this->authorize('accounts.manage');
        $this->resetValidation();
        $this->showForm = true;
        $this->spentOn = today()->toDateString();
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetValidation();
    }

    /** The direction this screen books — `out` for Expenses, `in` for Other income. */
    protected function direction(): ExpenseDirection
    {
        return ExpenseDirection::Out;
    }

    /** The blade both screens share. */
    protected function viewName(): string
    {
        return 'livewire.admin.accounts.expenses-index';
    }

    public function save(): void
    {
        $this->authorize('accounts.manage');

        $data = $this->validate([
            'description' => ['required', 'string', 'max:255'],
            'amountRupees' => ['required', 'numeric', 'gt:0'],
            'accountId' => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'vendorId' => ['nullable', 'integer', 'exists:vendors,id'],
            'spentOn' => ['required', 'date'],
            'paidFrom' => ['required', 'in:'.implode(',', PaidFrom::values())],
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $expense = app(RecordExpense::class)->handle([
                'direction' => $this->direction(),
                'account_id' => (int) $data['accountId'],
                'amount_minor' => Money::ofRupees((string) $data['amountRupees'])->minor,
                'spent_on' => $data['spentOn'],
                'paid_from' => $data['paidFrom'],
                'vendor_id' => $data['vendorId'] ?: null,
                'reference' => $data['reference'],
                'notes' => $data['notes'],
                'description' => $data['description'],
            ], $this->user());
        } catch (Throwable $exception) {
            $this->toast('Not recorded — '.$exception->getMessage(), 'error');

            return;
        }

        $this->reset('description', 'amountRupees', 'accountId', 'vendorId', 'reference', 'notes');
        $this->spentOn = today()->toDateString();
        $this->showForm = false;
        $this->toast($expense->direction->label().' recorded and posted.');
    }

    public function render(): View
    {
        $direction = $this->direction();

        $from = $this->filterFrom !== '' ? $this->filterFrom : today()->startOfYear()->toDateString();
        $to = $this->filterTo !== '' ? $this->filterTo : today()->toDateString();

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $query = Expense::query()
            ->where('direction', $direction)
            ->whereDate('spent_on', '>=', $from)
            ->whereDate('spent_on', '<=', $to);

        $rows = (clone $query)
            // Every relation the blade reads, eager-loaded — the app runs with lazy loading disabled.
            ->with(['account', 'paymentAccount', 'vendor', 'journalEntry', 'recurring'])
            ->orderByDesc('spent_on')
            ->orderByDesc('id')
            ->limit(300)
            ->get();

        // Summaries cover the complete filtered range, not only the 300 most recent detail rows.
        $byAccount = (clone $query)
            ->selectRaw('account_id, SUM(amount_minor) AS aggregate_total_minor')
            ->with('account')
            ->groupBy('account_id')
            ->orderByDesc('aggregate_total_minor')
            ->get()
            ->map(function (Expense $group): array {
                $account = $group->account;

                return [
                    'label' => $account === null ? 'Unknown account' : $account->code.' · '.$account->name,
                    'total' => (int) $group->getAttribute('aggregate_total_minor'),
                ];
            })->values();

        return view($this->viewName(), [
            'direction' => $direction,
            'heading' => $direction === ExpenseDirection::Out ? 'Expenses' : 'Other income',
            'blurb' => $direction === ExpenseDirection::Out
                ? 'Costs that are not a client invoice and not a bill from a supplier — fuel, fees, a repair. Each one posts the moment you save it.'
                : 'Money that came in and is not a client invoice — a scrap sale, interest, a refund. Each one posts the moment you save it.',
            'rows' => $rows,
            'total' => (int) $byAccount->sum('total'),
            'byAccount' => $byAccount,
            'accounts' => Account::query()
                ->where('type', $direction->accountType()->value)
                ->where('is_group', false)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'vendors' => Vendor::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'paidFromOptions' => PaidFrom::options(),
            'from' => $from,
            'to' => $to,
            'lakh' => app(SalesSettings::class)->lakh_grouping,
            'canManage' => $this->user()->can('accounts.manage'),
        ]);
    }
}
