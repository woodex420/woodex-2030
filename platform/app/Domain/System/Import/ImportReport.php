<?php

declare(strict_types=1);

namespace App\Domain\System\Import;

use Illuminate\Support\Facades\Storage;

/**
 * Counts, warnings and duplicates for one import run; written to storage/app/imports/{timestamp}/.
 */
final class ImportReport
{
    public const COLUMNS = ['source', 'created', 'merged', 'skipped', 'failed'];

    /** @var array<string, array<string, int>> */
    public array $counts = [];

    /** @var list<array{step: string, legacy_id: string, message: string}> */
    public array $warnings = [];

    /** @var list<array{step: string, legacy_id: string, matched_id: int, matched_by: string}> */
    public array $duplicates = [];

    public readonly float $startedAt;

    public ?float $finishedAt = null;

    public ?string $path = null;

    public function __construct(public readonly bool $dryRun)
    {
        $this->startedAt = microtime(true);
    }

    public function count(string $step, string $column, int $by = 1): void
    {
        $this->counts[$step] ??= array_fill_keys(self::COLUMNS, 0);
        $this->counts[$step][$column] += $by;
    }

    public function warn(string $step, string|int $legacyId, string $message): void
    {
        $this->warnings[] = ['step' => $step, 'legacy_id' => (string) $legacyId, 'message' => $message];
    }

    public function duplicate(string $step, string|int $legacyId, int $matchedId, string $matchedBy): void
    {
        $this->duplicates[] = ['step' => $step, 'legacy_id' => (string) $legacyId, 'matched_id' => $matchedId, 'matched_by' => $matchedBy];
    }

    public function finish(): void
    {
        $this->finishedAt = microtime(true);
    }

    public function seconds(): float
    {
        return round(($this->finishedAt ?? microtime(true)) - $this->startedAt, 2);
    }

    /**
     * Write report.json, warnings.csv and duplicates.csv; returns the directory (relative to the local disk).
     */
    public function write(?string $directory = null): string
    {
        $directory ??= 'imports/'.now()->format('Ymd-His').($this->dryRun ? '-dry-run' : '');
        $disk = Storage::disk('local');

        $disk->put("{$directory}/report.json", (string) json_encode([
            'dry_run' => $this->dryRun,
            'started_at' => date(DATE_ATOM, (int) $this->startedAt),
            'seconds' => $this->seconds(),
            'counts' => $this->counts,
            'warnings' => count($this->warnings),
            'duplicates' => count($this->duplicates),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $disk->put("{$directory}/warnings.csv", self::csv(['step', 'legacy_id', 'message'], $this->warnings));
        $disk->put("{$directory}/duplicates.csv", self::csv(['step', 'legacy_id', 'matched_id', 'matched_by'], $this->duplicates));

        return $this->path = $directory;
    }

    /**
     * @param  list<string>  $header
     * @param  list<array<string, string|int>>  $rows
     */
    private static function csv(array $header, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, $header, escape: '');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($key) => $row[$key] ?? '', $header), escape: '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
