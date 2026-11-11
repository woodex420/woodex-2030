<?php

declare(strict_types=1);

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Enums\TaskStatus;
use App\Domain\Crm\Enums\VisitStatus;
use App\Domain\Crm\Models\SiteVisit;
use App\Domain\Crm\Models\Task;
use App\Domain\Crm\Notifications\TaskReminderNotification;
use App\Domain\Crm\Notifications\VisitReminderNotification;
use App\Domain\System\Alerts\AlertRouter;
use Illuminate\Database\Eloquent\Collection;

/**
 * Staff reminders (CRM-007, CRM-008), run every minute by `woodex:reminders`:
 * - tasks at their reminder time (default 30 minutes before due);
 * - site visits 24 hours before ("tomorrow") and 2 hours before.
 * Each reminder is sent once (tasks.reminded_at, site_visits.reminders keys).
 */
final class SendReminders
{
    /**
     * @return array{tasks: int, visits: int}
     */
    public function handle(): array
    {
        return ['tasks' => $this->tasks(), 'visits' => $this->visits()];
    }

    public function tasks(): int
    {
        $sent = 0;

        Task::query()
            ->where('status', TaskStatus::Open)
            ->whereNull('reminded_at')
            ->whereNotNull('remind_at')
            ->where('remind_at', '<=', now())
            // Old reminders (e.g. the server was down for a day) are skipped, not sent late.
            ->where('remind_at', '>=', now()->subHours(6))
            ->whereNotNull('assignee_id')
            ->with(['assignee.telegramLink', 'lead.contact'])
            ->chunkById(100, function (Collection $tasks) use (&$sent): void {
                foreach ($tasks as $task) {
                    /** @var Task $task */
                    if (Task::query()->whereKey($task->id)->whereNull('reminded_at')->update(['reminded_at' => now()]) === 0) {
                        continue;
                    }

                    if ($task->assignee !== null && $task->assignee->is_active) {
                        app(AlertRouter::class)->send('task.due', [$task->assignee], new TaskReminderNotification($task));
                        $sent++;
                    }
                }
            });

        return $sent;
    }

    public function visits(): int
    {
        $sent = 0;

        SiteVisit::query()
            ->whereIn('status', [VisitStatus::Scheduled, VisitStatus::Confirmed, VisitStatus::Rescheduled])
            ->whereBetween('scheduled_at', [now(), now()->addHours(24)])
            ->with(['assignee.telegramLink', 'contact', 'lead.owner.telegramLink'])
            ->chunkById(100, function (Collection $visits) use (&$sent): void {
                foreach ($visits as $visit) {
                    /** @var SiteVisit $visit */
                    $reminders = $visit->reminders ?? [];
                    $minutesLeft = now()->diffInMinutes($visit->scheduled_at, false);
                    $key = $minutesLeft <= 120 ? 'staff_2h' : 'staff_d1';

                    if (isset($reminders[$key])) {
                        continue;
                    }

                    // The 2-hour reminder replaces a day-before one that was never sent.
                    $reminders[$key] = now()->toIso8601String();
                    if ($key === 'staff_2h') {
                        $reminders['staff_d1'] ??= $reminders[$key];
                    }
                    $visit->forceFill(['reminders' => $reminders])->save();

                    $recipient = $visit->assignee ?? $visit->lead?->owner;

                    if ($recipient !== null && $recipient->is_active) {
                        $when = $key === 'staff_2h'
                            ? 'in '.max(1, (int) round($minutesLeft / 60)).' h'
                            : ($visit->scheduled_at->isTomorrow() ? 'tomorrow' : 'today');
                        app(AlertRouter::class)->send('visit.reminder', [$recipient], new VisitReminderNotification($visit, $when));
                        $sent++;
                    }
                }
            });

        return $sent;
    }
}
