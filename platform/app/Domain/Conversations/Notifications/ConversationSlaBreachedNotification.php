<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Notifications;

use App\Domain\Conversations\Models\Conversation;
use App\Integrations\Telegram\TelegramMessage;
use App\Notifications\Concerns\SendsToDatabaseAndTelegram;
use Illuminate\Notifications\Notification;

/**
 * A WhatsApp conversation missed its reply target (CNV-007) — the conversation mirror of
 * the lead first-response SLA. Escalated means it is still unanswered after the delay.
 */
final class ConversationSlaBreachedNotification extends Notification
{
    use SendsToDatabaseAndTelegram;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly bool $escalated = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'conversation.sla',
            'title' => $this->escalated
                ? 'WhatsApp reply still missing — escalated'
                : 'WhatsApp reply target missed',
            'body' => ($this->conversation->contact->name ?? 'a client').' is waiting (was due '.$this->conversation->reply_due_at?->format('g:i a').').',
            'conversation_id' => $this->conversation->id,
            'url' => route('inbox'),
        ];
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        return new TelegramMessage(implode("\n", array_filter([
            '⏰ <b>'.($this->escalated ? 'Still no reply — escalated' : 'WhatsApp reply target missed').'</b>',
            '👤 '.TelegramMessage::escape($this->conversation->contact->name ?? 'a client'),
            $this->conversation->reply_due_at !== null ? '⏳ Was due '.TelegramMessage::escape($this->conversation->reply_due_at->format('g:i a')) : null,
            '📄 '.TelegramMessage::escape((string) $this->conversation->last_preview),
        ])), $this->openButton('Open inbox', route('inbox')));
    }
}
