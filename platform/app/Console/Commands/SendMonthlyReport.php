<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\System\Actions\GenerateMonthlyReport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * P8.5 monthly CRM report (the owner's 17 Oct decision): on the 1st at 09:00 the owner gets the
 * month that just ended — Telegram with email fallback, archive under System → Automations.
 * Gated in routes/console.php by the woodex:monthly-report switch, like every business automation.
 */
class SendMonthlyReport extends Command
{
    protected $signature = 'woodex:monthly-report
                            {--month= : First day of the month to report, e.g. 2026-09-01 (default: last month)}
                            {--no-send : Compute and archive only, do not message the owner}';

    protected $description = 'Build, archive and send the monthly CRM report (enquiries, quotations, money in, outstanding).';

    public function handle(GenerateMonthlyReport $generate): int
    {
        $month = (string) ($this->option('month') ?? '');

        $periodStart = $month !== ''
            ? CarbonImmutable::parse($month)->startOfDay()
            : null; // the action defaults to the month before today

        $report = $generate->handle($periodStart, send: ! (bool) $this->option('no-send'));

        $this->info('Monthly report for '.$report->periodLabel().' archived'.($report->notified_at !== null ? ' and sent.' : '.'));

        return self::SUCCESS;
    }
}
