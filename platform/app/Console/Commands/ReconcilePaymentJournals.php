<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Accounts\Reports\PaymentJournalReconciliation;
use Illuminate\Console\Command;

/** Operator-only CLI, no HTTP endpoint, customer details, export file or automatic financial correction. */
final class ReconcilePaymentJournals extends Command
{
    protected $signature = 'accounts:reconcile-payments';

    protected $description = 'Read-only summary of verified payments without payment journals; never posts or backfills.';

    public function handle(PaymentJournalReconciliation $report): int
    {
        $this->line(json_encode($report->summary(), JSON_THROW_ON_ERROR));
        $this->line('READ ONLY: no corrections applied. Review missing journals, dates and closed periods before any separately approved repair.');
        $this->line('Includes archived verified payments; no customer, bank or payment identifiers are printed.');
        $this->line('A matching source journal is a completeness check only; its amount, reversal and accounts still need reconciliation.');

        return self::SUCCESS;
    }
}
