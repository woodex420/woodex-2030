<?php

declare(strict_types=1);

namespace App\Domain\System\Notifications;

use App\Integrations\Telegram\TelegramMessage;
use App\Notifications\Concerns\SendsToDatabaseAndTelegram;
use Illuminate\Notifications\Notification;

/**
 * The month's WhatsApp spend crossed the studio's budget (WAP-008). Sent once per month,
 * on the day the estimate crosses the line — the daily check keeps the estimate current.
 */
final class WhatsAppUsageNotification extends Notification
{
    use SendsToDatabaseAndTelegram;

    public function __construct(
        public readonly string $month,
        public readonly int $text,
        public readonly int $template,
        public readonly int $cost,
        public readonly int $budget,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'whatsapp.usage',
            'title' => 'WhatsApp spend crossed the monthly budget',
            'body' => "Rs {$this->cost} so far in {$this->month} (budget Rs {$this->budget}): {$this->text} messages, {$this->template} templates.",
            'url' => route('admin.integrations'),
        ];
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        return new TelegramMessage(
            '💸 <b>WhatsApp spend crossed the monthly budget</b>'."\n"
            .'Rs '.TelegramMessage::escape((string) number_format($this->cost)).' of Rs '.TelegramMessage::escape((string) number_format($this->budget))." this month.\n"
            .'Messages: '.TelegramMessage::escape((string) $this->text).' · templates: '.TelegramMessage::escape((string) $this->template).'.',
            $this->openButton('Integrations', route('admin.integrations')),
        );
    }
}
