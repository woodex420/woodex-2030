<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Notifications;

use App\Domain\Conversations\Models\Message;
use App\Integrations\Telegram\TelegramMessage;
use App\Models\User;
use App\Notifications\Concerns\SendsToDatabaseAndTelegram;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Someone @mentioned you in an internal note on a conversation (CNV-003b).
 *
 * The note never reaches the client; the mention is the only thing that travels — in-app
 * and on Telegram, straight to the inbox thread.
 */
final class ConversationMentionedNotification extends Notification
{
    use SendsToDatabaseAndTelegram;

    public function __construct(
        public readonly Message $message,
        public readonly ?User $author = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'conversation.mention',
            'title' => ($this->author->name ?? 'Someone').' mentioned you in a conversation',
            'body' => Str::limit((string) $this->message->body, 140),
            'conversation_id' => $this->message->conversation_id,
            'url' => route('inbox'),
        ];
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $conversation = $this->message->conversation?->loadMissing('contact');

        return new TelegramMessage(implode("\n", array_filter([
            '💬 <b>'.TelegramMessage::escape($this->author->name ?? 'Someone').' mentioned you in a conversation</b>',
            $conversation?->contact?->name !== null && $conversation->contact->name !== '' ? '👤 '.TelegramMessage::escape($conversation->contact->name) : null,
            TelegramMessage::escape(Str::limit((string) $this->message->body, 500)),
        ])), $this->openButton('Open inbox', route('inbox')));
    }
}
