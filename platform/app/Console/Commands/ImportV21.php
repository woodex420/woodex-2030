<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\System\Import\ImportReport;
use App\Domain\System\Import\V21Importer;
use Illuminate\Console\Command;
use Throwable;

/**
 * Import CRM data from Woodex v2.1. Runbook: docs/08-V21-IMPORT-RUNBOOK.md.
 */
class ImportV21 extends Command
{
    protected $signature = 'woodex:import-v21
        {--dry-run : Run everything inside a transaction and roll it back (nothing is saved)}
        {--only=* : Limit to steps: users, clients, leads, notes, bookings}
        {--connection=legacy : Database connection that points at the v2.1 database}';

    protected $description = 'Import users, clients, leads, notes and bookings from Woodex v2.1 (safe to re-run).';

    public function handle(V21Importer $importer): int
    {
        $only = collect((array) $this->option('only'))
            ->flatMap(fn ($value) => explode(',', (string) $value))
            ->map(fn ($value) => trim($value))
            ->filter()
            ->values()
            ->all();

        $unknown = array_diff($only, V21Importer::STEPS);

        if ($unknown !== []) {
            $this->components->error('Unknown step(s): '.implode(', ', $unknown).'. Use: '.implode(', ', V21Importer::STEPS).'.');

            return self::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');
        $connection = (string) $this->option('connection');

        $this->components->info(($dryRun ? 'DRY RUN — nothing will be saved. ' : '')."Reading v2.1 data from the \"{$connection}\" connection…");

        try {
            $report = $importer->run($connection, $only, $dryRun, function (string $step, int $rows): void {
                $this->output->write("\r  {$step}: {$rows} rows…");
            });
        } catch (Throwable $exception) {
            $this->newLine();
            $this->components->error('Import stopped and was rolled back: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->renderReport($report);

        $directory = $report->write();
        $this->components->twoColumnDetail('Report', "storage/app/private/{$directory}");

        if ($dryRun) {
            $this->components->warn('Dry run finished. Review the warnings, then run again without --dry-run.');
        } else {
            $this->components->info('Import finished. Imported staff sign in via “Resend invite” in Users & roles.');
        }

        return self::SUCCESS;
    }

    private function renderReport(ImportReport $report): void
    {
        $rows = [];

        foreach ($report->counts as $step => $counts) {
            $rows[] = array_merge([$step], array_values($counts));
        }

        $this->table(array_merge(['Step'], array_map('ucfirst', ImportReport::COLUMNS)), $rows);
        $this->components->twoColumnDetail('Warnings', (string) count($report->warnings));
        $this->components->twoColumnDetail('Duplicates merged', (string) count($report->duplicates));
        $this->components->twoColumnDetail('Time', $report->seconds().'s');
    }
}
