<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Accounts\Actions\RaiseRecurringExpense;
use App\Domain\Accounts\Models\RecurringExpense;
use Illuminate\Console\Command;
use Throwable;

/**
 * Repeating overheads become expenses (slice I): rent, salaries, a subscription.
 *
 * Each schedule raises at most one row per period — the action refuses a date that has not arrived and then
 * advances it from itself. A failure on one schedule does not stop the rest, and nothing is swallowed: the
 * row is named in the output and the command exits non-zero so the health screen shows it.
 */
final class ExpenseRecurringCommand extends Command
{
    protected $signature = 'woodex:expense-recurring {--dry-run}';

    protected $description = 'Raise and post the expense for every recurring overhead that is due today.';

    public function handle(RaiseRecurringExpense $raise): int
    {
        $dry = (bool) $this->option('dry-run');

        $schedules = RecurringExpense::query()
            ->due()
            ->with(['account', 'vendor'])
            ->orderBy('next_run_on')
            ->get();

        $raised = 0;
        $failed = 0;

        foreach ($schedules as $schedule) {
            if ($dry) {
                $this->line(sprintf('  would post %s (%s)', $schedule->description, $schedule->frequency->description()));

                continue;
            }

            try {
                $expense = $raise->handle($schedule);

                if ($expense === null) {
                    continue;
                }

                $raised++;

                $this->line(sprintf('  %s → %s', $schedule->description, $expense->spent_on->toDateString()));
            } catch (Throwable $exception) {
                $failed++;

                $this->components->error(sprintf('%s: %s', $schedule->description, $exception->getMessage()));
            }
        }

        $this->components->info(sprintf(
            '%s %d recurring expense(s) from %d due schedule(s)%s.',
            $dry ? 'Would raise' : 'Raised',
            $raised,
            $schedules->count(),
            $failed > 0 ? ", {$failed} failed" : '',
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
