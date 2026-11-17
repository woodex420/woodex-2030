<?php

declare(strict_types=1);

namespace App\Domain\System\Notifications;

use App\Domain\Conversations\Models\Conversation;
use App\Integrations\Telegram\TelegramMessage;
use App\Notifications\Concerns\SendsToDatabaseAndTelegram;
use Illuminate\Notifications\Notification;

/**
 * The assistant put a conversation down and picked up a human (BOT-006): the visitor asked for
 * one, or said something the guardrails would not let the bot answer, or the thread or the day
 * hit its limit. The inbox is where the reply happens, so that is where the notification goes.
 */
final class BotHandoffNotification extends Notification
{
    use SendsToDatabaseAndTelegram;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly string $reason,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'bot.handoff',
            'title' => 'Chat needs a human',
            'body' => 'A website chat is waiting for the team ('.$this->reason.').',
            'url' => route('inbox'),
        ];
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        return new TelegramMessage(
            '🤝 <b>Chat needs a human</b>'."\n"
            .'A website chat is waiting for the team.'."\n"
            .'Why: '.TelegramMessage::escape($this->reason),
            $this->openButton('Open the inbox', route('inbox')),
        );
    }
}
