<?php

declare(strict_types=1);

namespace App\Domain\System\Jobs;

use App\Domain\System\Import\V21Importer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Runs the v2.1 import from the Data import screen (SYS-006) on the cron-driven queue worker.
 * Progress is shared through the cache key "woodex:import:status", which also stops a second run being
 * queued while one is waiting or running.
 */
final class RunV21Import implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const STATUS_KEY = 'woodex:import:status';

    public int $tries = 1;

    public int $timeout = 3000;

    /**
     * @param  list<string>  $only
     */
    public function __construct(public readonly bool $dryRun, public readonly array $only = [], public readonly ?int $userId = null) {}

    public function handle(V21Importer $importer): void
    {
        self::status('running', ($this->dryRun ? 'Dry run' : 'Import').' running…');

        try {
            $report = $importer->run('legacy', $this->only, $this->dryRun, function (string $step, int $rows): void {
                self::status('running', "{$step}: {$rows} rows…");
            });
            $directory = $report->write();
            self::status('finished', ($this->dryRun ? 'Dry run' : 'Import').' finished.', $directory);
        } catch (Throwable $exception) {
            self::status('failed', 'Stopped and rolled back: '.$exception->getMessage());
            report($exception);
        }
    }

    public static function status(string $state, string $message, ?string $directory = null): void
    {
        Cache::put(self::STATUS_KEY, ['state' => $state, 'message' => $message, 'directory' => $directory, 'at' => now()->toIso8601String()], now()->addDay());
    }
}
