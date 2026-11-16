<?php

declare(strict_types=1);

namespace App\Domain\System\Notifications;

use App\Integrations\Telegram\TelegramMessage;
use App\Notifications\Concerns\SendsToDatabaseAndTelegram;
use Illuminate\Notifications\Notification;

/**
 * Meta downgraded the quality rating of the studio's WhatsApp number (WAP-010).
 * A low rating throttles messaging, so this is worth the same day it happens.
 */
final class WhatsAppQualityNotification extends Notification
{
    use SendsToDatabaseAndTelegram;

    public function __construct(
        public readonly ?string $from,
        public readonly ?string $to,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'whatsapp.quality',
            'title' => 'WhatsApp number quality downgraded',
            'body' => 'Meta moved the number from '.($this->from ?? 'unknown').' to '.($this->to ?? 'unknown').'. Check Meta Business Manager for the reason.',
            'url' => route('admin.integrations'),
        ];
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        return new TelegramMessage(
            '📉 <b>WhatsApp number quality downgraded</b>'."\n"
            .'Meta moved the studio number from '.TelegramMessage::escape((string) ($this->from ?? 'unknown')).' to '.TelegramMessage::escape((string) ($this->to ?? 'unknown')).".\n"
            .'Check Meta Business Manager for the reason — a low rating throttles messaging.',
            $this->openButton('Integrations', route('admin.integrations')),
        );
    }
}
