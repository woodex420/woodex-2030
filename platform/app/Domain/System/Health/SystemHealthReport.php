<?php

declare(strict_types=1);

namespace App\Domain\System\Health;

use App\Console\Commands\Heartbeat;
use App\Domain\System\Models\ScheduledTaskRun;
use App\Integrations\Telegram\Contracts\TelegramGateway;
use App\Integrations\Turnstile\TurnstileVerifier;
use App\Support\Install\DatabaseUpdates;
use App\Support\Install\SiteState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The one collector behind both System → Health and /health (SYS-008, S5): database size, last backup,
 * scheduled tasks with their last run, plus the checks the screen already had. Two screens, one truth —
 * if these disagree, the bug is here, not in whoever is reading it.
 */
final class SystemHealthReport
{
    public function __construct(
        private readonly DatabaseUpdates $updates,
        private readonly SiteState $site,
    ) {}

    /**
     * @return list<array{label: string, ok: bool, value: string, hint: string}>
     */
    public function checks(): array
    {
        $checks = [
            $this->databaseUpToDate(),
            $this->stateFolder(),
            $this->frontEndBuild(),
            $this->cronHeartbeat(),
            $this->queuedJobs(),
            $this->failedJobs(),
            $this->diskSpace(),
            $this->lastBackup(),
            $this->scheduledTasks(),
            $this->databaseSizeCheck(),
            $this->debugMode(),
            $this->telegramBot(),
            $this->turnstile(),
        ];

        return $checks;
    }

    /**
     * @return array<string, string>
     */
    public function environment(): array
    {
        return [
            'App environment' => app()->environment(),
            'PHP' => PHP_VERSION,
            'Laravel' => app()->version(),
            'Database' => config('database.default'),
            'Queue' => config('queue.default'),
            'Cache' => config('cache.default'),
            'Time zone' => config('app.timezone'),
        ];
    }

    /**
     * Total size of the live database (S5). Driver-aware: information_schema on MySQL, pragma on SQLite,
     * null when neither answers — an unknown size must never read as a failure.
     */
    public function databaseSize(): ?string
    {
        $bytes = match (config('database.default')) {
            'mysql' => $this->mysqlSize(),
            'sqlite' => $this->sqliteSize(),
            default => null,
        };

        if ($bytes === null || $bytes <= 0) {
            return null;
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        foreach ($units as $i => $unit) {
            if ($bytes < 1024 || $i === count($units) - 1) {
                return round((float) $bytes, $i === 0 ? 0 : 1).' '.$unit;
            }

            $bytes /= 1024;
        }

        return null;
    }

    /**
     * When the newest backup zip was written (S5). spatie/laravel-backup keeps them on the local disk,
     * so the file's timestamp is the truth — no log to parse.
     */
    public function lastBackupAt(): ?CarbonImmutable
    {
        $newest = null;

        foreach (Storage::disk('local')->files() as $file) {
            if (! str_ends_with(strtolower($file), '.zip')) {
                continue;
            }

            $modified = Storage::disk('local')->lastModified($file);

            if ($newest === null || $modified > $newest) {
                $newest = $modified;
            }
        }

        return $newest !== null ? CarbonImmutable::createFromTimestampUTC($newest) : null;
    }

    /**
     * @return array<string, ScheduledTaskRun> task => latest run
     */
    public function taskRuns(): array
    {
        return ScheduledTaskRun::latestPerTask();
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseUpToDate(): array
    {
        $pending = count($this->updates->pending());

        return [
            'label' => 'Database up to date',
            'ok' => $pending === 0,
            'value' => $pending === 0 ? 'current' : $pending.' pending',
            'hint' => 'After a deploy: System → Database update, or php artisan woodex:update.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stateFolder(): array
    {
        return [
            'label' => 'Settings and uploads folder',
            // Classic is right for the SSH install (code outside public_html); a Git deploy may replace it.
            'ok' => ! ($this->site->usesClassicLayout() && $this->site->insideWebFolder() && app()->isProduction()),
            'value' => $this->site->usesExternalState() ? 'outside the site folder' : 'platform/ (classic)',
            'hint' => $this->site->usesExternalState()
                ? $this->site->displayPath((string) $this->site->environmentDirectory()).' survives every Git deploy.'
                : 'Fine for SSH installs. With Hostinger Git deploys move .env and storage/ to ~/woodex-os/'.$this->site->siteKey().' (docs/06 §2).',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function frontEndBuild(): array
    {
        return [
            'label' => 'Front-end build',
            'ok' => is_file(public_path('build/manifest.json')),
            'value' => is_file(public_path('build/manifest.json')) ? 'present' : 'missing',
            'hint' => 'platform/public/build is committed to Git (npm run build before each commit).',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cronHeartbeat(): array
    {
        $heartbeat = Cache::get(Heartbeat::CACHE_KEY);
        $heartbeatAt = is_string($heartbeat) && $heartbeat !== '' ? CarbonImmutable::parse($heartbeat) : null;

        return [
            'label' => 'Cron heartbeat',
            'ok' => $heartbeatAt !== null && $heartbeatAt->greaterThan(now()->subMinutes(3)),
            'value' => $heartbeatAt?->diffForHumans() ?? 'never',
            'hint' => 'hPanel cron must run "artisan schedule:run" every minute.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function queuedJobs(): array
    {
        return [
            'label' => 'Queued jobs waiting',
            'ok' => ($pending = DB::table('jobs')->count()) < 100,
            'value' => (string) $pending,
            'hint' => 'Drained by the scheduler every minute.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failedJobs(): array
    {
        return [
            'label' => 'Failed jobs',
            'ok' => ($failed = DB::table('failed_jobs')->count()) === 0,
            'value' => (string) $failed,
            'hint' => 'Retry with "php artisan queue:retry all".',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function diskSpace(): array
    {
        $diskFree = @disk_free_space(storage_path());
        $diskTotal = @disk_total_space(storage_path());

        return [
            'label' => 'Disk space free',
            'ok' => $diskFree !== false && $diskTotal ? ($diskFree / $diskTotal) > 0.15 : true,
            'value' => $diskFree !== false ? number_format($diskFree / 1024 / 1024 / 1024, 1).' GB' : 'n/a',
            'hint' => 'Keep at least 15% free for backups and uploads.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lastBackup(): array
    {
        $at = $this->lastBackupAt();
        $ok = $at !== null && $at->greaterThan(now()->subHours(48));

        return [
            'label' => 'Last backup',
            'ok' => $ok,
            'value' => $at?->diffForHumans() ?? 'never',
            'hint' => 'Daily 02:30 (database) and Sunday 03:00 (full). Keep a copy outside the server too.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduledTasks(): array
    {
        $runs = $this->taskRuns();

        if ($runs === []) {
            return [
                'label' => 'Scheduled tasks',
                'ok' => false,
                'value' => 'no runs recorded yet',
                'hint' => 'Each task records its run here once the scheduler has ticked at least once.',
            ];
        }

        // Everything the scheduler runs at least daily must have run within 25 hours.
        $stale = [];

        foreach ($runs as $task => $run) {
            if ($run->ran_at === null || $run->ran_at->lessThan(now()->subHours(25))) {
                $stale[] = $task;
            }
        }

        $value = count($runs).' tasks recorded';

        if ($stale !== []) {
            $value .= ' · stale: '.implode(', ', array_slice($stale, 0, 2)).(count($stale) > 2 ? '…' : '');
        }

        return [
            'label' => 'Scheduled tasks',
            'ok' => $stale === [],
            'value' => $value,
            'hint' => 'woodex:heartbeat, queue, SLA and reminder tasks — stale means the cron entry or a task is missing.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseSizeCheck(): array
    {
        $size = $this->databaseSize();

        return [
            'label' => 'Database size',
            'ok' => true,
            'value' => $size ?? 'unknown',
            'hint' => 'Grows with quotes, invoices and uploads. Hostinger plans have a database quota.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function debugMode(): array
    {
        return [
            'label' => 'Debug mode off in production',
            'ok' => ! (app()->isProduction() && config('app.debug')),
            'value' => config('app.debug') ? 'debug on' : 'debug off',
            'hint' => 'APP_DEBUG=false on the server.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function telegramBot(): array
    {
        $configured = app(TelegramGateway::class)->isConfigured();

        return [
            'label' => 'Telegram bot',
            'ok' => $configured,
            'value' => $configured ? 'configured' : 'not configured',
            'hint' => 'TELEGRAM_BOT_TOKEN, then php artisan woodex:telegram:set-webhook',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function turnstile(): array
    {
        $enabled = app(TurnstileVerifier::class)->enabled();

        return [
            'label' => 'Spam protection (Turnstile)',
            'ok' => $enabled,
            'value' => $enabled ? 'on' : 'off',
            'hint' => 'TURNSTILE_SITE_KEY and TURNSTILE_SECRET_KEY.',
        ];
    }

    private function mysqlSize(): ?int
    {
        try {
            $row = DB::selectOne(
                'SELECT SUM(data_length + index_length) AS size FROM information_schema.tables WHERE table_schema = ?',
                [DB::connection()->getDatabaseName()],
            );

            $size = $row->size ?? null;

            return is_numeric($size) ? (int) $size : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function sqliteSize(): ?int
    {
        try {
            $row = DB::selectOne('SELECT (SELECT page_count FROM pragma_page_count()) * (SELECT page_size FROM pragma_page_size()) AS size');

            return is_object($row) && is_numeric($row->size ?? null) ? (int) $row->size : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
