<?php

declare(strict_types=1);

namespace App\Domain\System\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per finished scheduler run (SYS-008, S5): the Health screen and /health show when a task last
 * ran, so a silently missing cron entry is caught even when the heartbeat keeps ticking.
 */
class ScheduledTaskRun extends Model
{
    public const RETAIN_DAYS = 14;

    protected $guarded = ['id'];

    /** ran_at is the only timestamp that matters here — rows are immutable history. */
    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ran_at' => 'datetime',
            'runtime_ms' => 'integer',
            'ok' => 'bool',
        ];
    }

    /**
     * Latest run per task, freshest last: task => the run.
     *
     * @return array<string, self>
     */
    public static function latestPerTask(): array
    {
        $rows = static::query()
            ->whereIn('id', static::query()->selectRaw('max(id)')->groupBy('task'))
            ->orderBy('task')
            ->get();

        $latest = [];

        foreach ($rows as $row) {
            $latest[$row->task] = $row;
        }

        return $latest;
    }

    /**
     * Scheduled task runs are only interesting while fresh; a fortnight is far beyond any alert window.
     */
    public static function pruneOld(): int
    {
        return static::query()->where('ran_at', '<', now()->subDays(self::RETAIN_DAYS))->delete();
    }
}
