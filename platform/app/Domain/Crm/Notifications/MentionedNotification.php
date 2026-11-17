<?php

declare(strict_types=1);

namespace App\Domain\Crm\Notifications;

use App\Domain\Crm\Models\Activity;
use App\Integrations\Telegram\TelegramMessage;
use App\Models\User;
use App\Notifications\Concerns\SendsToDatabaseAndTelegram;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Someone @mentioned you in a note (GLB-005).
 */
final class MentionedNotification extends Notification
{
    use SendsToDatabaseAndTelegram;

    public function __construct(public readonly Activity $activity, public readonly ?User $author = null) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'mention',
            'title' => ($this->author->name ?? 'Someone').' mentioned you'.($this->activity->lead ? ' on '.$this->activity->lead->ref : ''),
            'body' => Str::limit((string) $this->activity->body, 140),
            'activity_id' => $this->activity->id,
            'url' => $this->url(),
        ];
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $lead = $this->activity->lead;

        return new TelegramMessage(implode("\n", array_filter([
            '💬 <b>'.TelegramMessage::escape($this->author->name ?? 'Someone').' mentioned you</b>'.($lead ? ' · '.TelegramMessage::escape($lead->ref) : ''),
            $lead ? '👤 '.TelegramMessage::escape($lead->contact->name ?? '') : null,
            TelegramMessage::escape(Str::limit((string) $this->activity->body, 500)),
        ])), $this->openButton('Open', $this->url()));
    }

    private function url(): string
    {
        return match (true) {
            $this->activity->lead_id !== null => route('admin.leads.show', $this->activity->lead_id),
            $this->activity->contact_id !== null => route('admin.contacts.show', $this->activity->contact_id),
            default => route('admin.notifications'),
        };
    }
}
