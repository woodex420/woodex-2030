<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Sales\Actions\IssueRecurringInvoice;
use App\Domain\Sales\Models\RecurringInvoice;
use Illuminate\Console\Command;

/**
 * Standing charges that are due today become invoices (slice E, part 2).
 *
 * Each schedule bills at most once per period: the action refuses a schedule whose next date has not arrived,
 * then moves that date forward by its own frequency. A missed day is caught on the next run — one invoice, not a
 * backlog — and a schedule past its end date switches itself off.
 */
final class InvoiceRecurringCommand extends Command
{
    protected $signature = 'woodex:invoice-recurring {--dry-run}';

    protected $description = 'Raise the invoice for every recurring schedule that is due today.';

    public function handle(IssueRecurringInvoice $issue): int
    {
        $dry = (bool) $this->option('dry-run');

        $schedules = RecurringInvoice::query()
            ->due()
            ->with('contact')
            ->orderBy('next_issue_on')
            ->get();

        $raised = 0;

        foreach ($schedules as $schedule) {
            if ($dry) {
                $this->line(sprintf('  would bill %s (%s)', $schedule->label(), $schedule->frequency->description()));

                continue;
            }

            $invoice = $issue->handle($schedule);

            if ($invoice === null) {
                continue;
            }

            $raised++;

            $this->line(sprintf('  %s → %s (%s)', $schedule->label(), $invoice->number, $invoice->status->label()));
        }

        $this->components->info(sprintf(
            '%s %d recurring invoice(s) from %d due schedule(s).',
            $dry ? 'Would raise' : 'Raised',
            $raised,
            $schedules->count(),
        ));

        return self::SUCCESS;
    }
}
