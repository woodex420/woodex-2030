<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Enums\ExpenseDirection;
use App\Domain\Accounts\Models\Expense;
use App\Domain\Accounts\Models\RecurringExpense;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Raise the expense a repeating overhead is due (slice I). The date is the period the schedule names, not
 * "today", so a run five days late books the right month; the next date is advanced from that date, and a
 * schedule past its end deactivates itself. One row per due date — a second run the same day raises nothing.
 */
final class RaiseRecurringExpense
{
    public function __construct(
        private readonly RecordExpense $record,
    ) {}

    public function handle(RecurringExpense $schedule, ?User $actor = null): ?Expense
    {
        return DB::transaction(function () use ($schedule, $actor): ?Expense {
            $schedule = RecurringExpense::query()->lockForUpdate()->findOrFail($schedule->id);

            if (! $schedule->is_active || $schedule->next_run_on->isAfter(today())) {
                return null;
            }

            $already = Expense::query()
                ->where('recurring_expense_id', $schedule->id)
                ->whereDate('spent_on', $schedule->next_run_on->toDateString())
                ->exists();

            if ($already) {
                // The row exists but the schedule did not move (a crash between them): move it forward.
                $this->advance($schedule);

                return null;
            }

            $actor ??= $this->systemActor();

            $expense = $this->record->handle([
                'direction' => ExpenseDirection::Out,
                'account_id' => $schedule->account_id,
                'amount_minor' => (int) $schedule->amount_minor,
                'spent_on' => $schedule->next_run_on->toDateString(),
                'paid_from' => $schedule->paid_from,
                'vendor_id' => $schedule->vendor_id,
                'reference' => $schedule->description,
                'notes' => 'Raised by the recurring schedule.',
                'recurring_expense_id' => $schedule->id,
                'description' => $schedule->description,
            ], $actor);

            $schedule->forceFill([
                'raised_count' => (int) $schedule->raised_count + 1,
                'last_raised_at' => now(),
            ])->save();

            $this->advance($schedule);

            return $expense;
        });
    }

    private function advance(RecurringExpense $schedule): void
    {
        $next = $schedule->advanceFrom($schedule->next_run_on);
        $finished = $schedule->ends_on !== null && $next->greaterThan($schedule->ends_on);

        $schedule->forceFill([
            'next_run_on' => $next,
            'is_active' => $finished ? false : $schedule->is_active,
        ])->save();
    }

    private function systemActor(): User
    {
        foreach (['owner', 'admin'] as $role) {
            $user = User::query()->role($role)->where('is_active', true)->orderBy('id')->first();

            if ($user !== null) {
                return $user;
            }
        }

        return User::query()->where('is_active', true)->orderBy('id')->firstOrFail();
    }
}
