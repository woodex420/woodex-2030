<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Accounts;

use App\Domain\Accounts\Actions\RaiseRecurringExpense;
use App\Domain\Accounts\Enums\PaidFrom;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\RecurringExpense;
use App\Domain\Accounts\Models\Vendor;
use App\Domain\Sales\Enums\RecurringFrequency;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Settings\SalesSettings;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Repeating overheads (slice I): rent, salaries, a subscription. The schedule raises and posts the expense on its
 * own date through `woodex:expense-recurring` (daily, 09:10) — this screen is where the schedule is typed, paused
 * and audited, and where "raise it now" works for a schedule that is already due.
 */
#[Layout('layouts::admin')]
#[Title('Recurring expenses')]
class RecurringExpenses extends Component
{
    use InteractsWithAdmin;

    public bool $showForm = false;

    public string $description = '';

    public string $amountRupees = '';

    public ?int $accountId = null;

    public ?int $vendorId = null;

    public string $paidFrom = 'bank';

    public string $frequency = 'monthly';

    public string $startsOn = '';

    public string $endsOn = '';

    public function mount(): void
    {
        $this->authorize('accounts.view');
        $this->startsOn = today()->toDateString();
    }

    public function openForm(): void
    {
        $this->authorize('accounts.manage');
        $this->resetValidation();
        $this->showForm = true;
        $this->startsOn = today()->toDateString();
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->authorize('accounts.manage');

        $data = $this->validate([
            'description' => ['required', 'string', 'max:160'],
            'amountRupees' => ['required', 'numeric', 'gt:0'],
            'accountId' => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'vendorId' => ['nullable', 'integer', 'exists:vendors,id'],
            'paidFrom' => ['required', 'in:cash,bank,wallet'],
            'frequency' => ['required', 'in:'.implode(',', RecurringFrequency::values())],
            'startsOn' => ['required', 'date'],
            'endsOn' => ['nullable', 'date', 'after:startsOn'],
        ]);

        RecurringExpense::query()->create([
            'description' => $data['description'],
            'amount_minor' => Money::ofRupees((string) $data['amountRupees'])->minor,
            'account_id' => (int) $data['accountId'],
            'vendor_id' => $data['vendorId'] ?: null,
            'paid_from' => $data['paidFrom'],
            'frequency' => $data['frequency'],
            'starts_on' => $data['startsOn'],
            'next_run_on' => $data['startsOn'],
            'ends_on' => $data['endsOn'] !== '' ? $data['endsOn'] : null,
            'is_active' => true,
            'created_by' => $this->user()->id,
        ]);

        $this->reset('description', 'amountRupees', 'accountId', 'vendorId', 'endsOn');
        $this->showForm = false;
        $this->toast('Schedule saved — the daily run posts it from its start date.');
    }

    public function toggle(int $id): void
    {
        $this->authorize('accounts.manage');

        $schedule = RecurringExpense::query()->findOrFail($id);
        $schedule->update(['is_active' => ! $schedule->is_active]);

        $this->toast($schedule->description.($schedule->is_active ? ' is running again.' : ' is paused — nothing will post.'));
    }

    /** Raise one schedule now — the same action the scheduler uses, so the result is identical. */
    public function raiseNow(int $id): void
    {
        $this->authorize('accounts.manage');

        $schedule = RecurringExpense::query()->findOrFail($id);

        try {
            $expense = app(RaiseRecurringExpense::class)->handle($schedule, $this->user());
        } catch (Throwable $exception) {
            $this->toast('Not raised — '.$exception->getMessage(), 'error');

            return;
        }

        $this->toast($expense === null
            ? $schedule->description.' is not due yet (next run '.$schedule->refresh()->next_run_on->format('j M Y').').'
            : $schedule->description.' posted for '.$expense->spent_on->format('j M Y').'.');
    }

    public function render(): View
    {
        return view('livewire.admin.accounts.recurring-expenses', [
            'schedules' => RecurringExpense::query()
                ->with(['account', 'vendor'])
                ->withCount('expenses')
                ->orderByRaw('is_active desc')
                ->orderBy('next_run_on')
                ->get(),
            'accounts' => Account::query()
                ->where('type', 'expense')
                ->where('is_group', false)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'vendors' => Vendor::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'frequencies' => RecurringFrequency::options(),
            'paidFromOptions' => PaidFrom::options(),
            'monthlyCommitment' => (int) RecurringExpense::query()
                ->where('is_active', true)
                ->get()
                ->sum(fn (RecurringExpense $schedule): int => match ($schedule->frequency) {
                    RecurringFrequency::Monthly => (int) $schedule->amount_minor,
                    RecurringFrequency::Quarterly => intdiv((int) $schedule->amount_minor, 3),
                    RecurringFrequency::Yearly => intdiv((int) $schedule->amount_minor, 12),
                }),
            'lakh' => app(SalesSettings::class)->lakh_grouping,
            'canManage' => $this->user()->can('accounts.manage'),
        ]);
    }
}
