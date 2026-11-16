<?php

declare(strict_types=1);

namespace App\Domain\Crm\Notifications;

use App\Domain\Crm\Models\Task;
use App\Integrations\Telegram\TelegramMessage;
use App\Notifications\Concerns\SendsToDatabaseAndTelegram;
use Illuminate\Notifications\Notification;

/**
 * Task due soon (CRM-007): sent at the task's reminder time (default 30 minutes before it is due).
 */
final class TaskReminderNotification extends Notification
{
    use SendsToDatabaseAndTelegram;

    public function __construct(public readonly Task $task) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'task.reminder',
            'title' => 'Task due '.($this->task->due_at?->diffForHumans() ?? 'soon'),
            'body' => $this->task->title.($this->task->lead ? " · {$this->task->lead->ref}" : ''),
            'task_id' => $this->task->id,
            'url' => $this->url(),
        ];
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $task = $this->task;

        return new TelegramMessage(implode("\n", array_filter([
            '⏰ <b>Task due '.TelegramMessage::escape($task->due_at?->format('g:i a') ?? 'soon').'</b>',
            '📝 '.TelegramMessage::escape($task->title),
            $task->lead ? '👤 '.TelegramMessage::escape(($task->lead->contact->name ?? '').' · '.$task->lead->ref) : null,
        ])), $this->openButton('Open', $this->url()));
    }

    private function url(): string
    {
        return $this->task->lead_id !== null
            ? route('admin.leads.show', $this->task->lead_id)
            : route('admin.tasks.index');
    }
}
