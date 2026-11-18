<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\System\Import\LeadCsvImporter;
use App\Models\User;
use Illuminate\Console\Command;

/** No source rows or customer identifiers are printed or written into public artifacts. */
final class ImportLeadCsv extends Command
{
    protected $signature = 'woodex:import-lead-csv {path} {--user= : Active owner user ID} {--batch=jan-2025-leads} {--year=2025} {--apply : Apply new contacts only; otherwise report without writes}';

    protected $description = 'Review or import historical lead CSV rows, preserving all existing contacts';

    public function handle(LeadCsvImporter $importer): int
    {
        $actor = User::query()->findOrFail((int) $this->option('user'));
        $result = $importer->handle((string) $this->argument('path'), $actor, (bool) $this->option('apply'), (string) $this->option('batch'), (int) $this->option('year'));
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
