<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\System\Models\ScheduledTaskRun;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records every finished scheduler task (SYS-008, S5) so System → Health and /health can say not just
 * "cron is alive" but "which task stopped running". A recording failure must never break the task itself.
 */
final class RecordScheduledTaskRun
{
    public function handle(ScheduledTaskFinished $event): void
    {
        $task = $event->task->command ?? $event->task->description;

        if (! is_string($task) || $task === '') {
            return;
        }

        // The scheduler stores commands as "'php' 'artisan' woodex:heartbeat"; the artisan part is the name
        // worth showing on the Health screen.
        if (preg_match("/artisan['\"]?\s+(.+)/", $task, $matches) === 1) {
            $task = trim($matches[1]);
        }

        try {
            ScheduledTaskRun::query()->create([
                'task' => Str::limit($task, 190, ''),
                'ran_at' => now(),
                'runtime_ms' => (int) round($event->runtime * 1000),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
