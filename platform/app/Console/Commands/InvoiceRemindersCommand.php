<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Sales\Actions\InvoiceReminderLadder;
use Illuminate\Console\Command;

/**
 * The overdue ladder on the daily schedule (slice E). One rung per invoice — 7, 14 and 30 days past due —
 * and the studio is told what went out and what did not. The command is gated by its Automation switch in
 * routes/console.php, so pausing it on the Automation screen stops every client message at once.
 */
final class InvoiceRemindersCommand extends Command
{
    protected $signature = 'woodex:invoice-reminders';

    protected $description = 'Send the 7/14/30-day payment reminder rung for every invoice that is past due.';

    public function handle(InvoiceReminderLadder $ladder): int
    {
        $result = $ladder->handle();

        if ($result['invoices'] === 0) {
            $this->components->info('No invoice is past due.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            '%d invoice(s) past due · %d rung(s) fired: %d messaged on WhatsApp, %d held for a call.',
            $result['invoices'],
            $result['fired'],
            $result['delivered'],
            $result['held'],
        ));

        return self::SUCCESS;
    }
}
