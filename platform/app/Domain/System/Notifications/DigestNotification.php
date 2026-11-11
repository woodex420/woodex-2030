<?php

declare(strict_types=1);

namespace App\Domain\System\Notifications;

use App\Integrations\Telegram\TelegramMessage;
use App\Notifications\Concerns\SendsToDatabaseAndTelegram;
use Illuminate\Notifications\Notification;

/**
 * S4 digests: the pre-built daily or weekly summary, in one message. The text is
 * composed per staff member (permission-aware) before the notification exists, so the
 * Telegram copy and the in-app bell copy can never disagree.
 */
final class DigestNotification extends Notification
{
    use SendsToDatabaseAndTelegram;

    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $text,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => mb_strimwidth($this->text, 0, 600, '…'),
            'url' => route('admin.dashboard'),
        ];
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        return new TelegramMessage($this->text);
    }
}
