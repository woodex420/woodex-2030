<?php

declare(strict_types=1);

namespace App\Domain\Sales\Notifications;

use App\Domain\Sales\Models\Invoice;
use App\Integrations\Telegram\TelegramMessage;
use App\Notifications\Concerns\SendsToDatabaseAndTelegram;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Money that is late, told to the people who can chase it (slice E). The message is the ladder's own
 * record: which rung fired, how late the invoice is, and — when the client was not messaged — exactly why,
 * so the next step is a phone call, not a guess.
 */
final class OverdueInvoiceNotification extends Notification implements ShouldQueue
{
    use Queueable, SendsToDatabaseAndTelegram;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly int $rung,
        public readonly int $daysLate,
        public readonly bool $clientMessaged,
        public readonly string $reason,
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'invoice.overdue',
            'title' => $this->title(),
            'body' => $this->summary(),
            'url' => route('admin.payments.index', ['tab' => 'receivables']),
        ];
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $text = '⏰ <b>'.TelegramMessage::escape($this->title()).'</b> · '.TelegramMessage::escape($this->invoice->contact->name)."\n"
            .TelegramMessage::escape($this->summary());

        return new TelegramMessage($text, [
            ...$this->openButton('Open receivables', route('admin.payments.index', ['tab' => 'receivables'])),
        ]);
    }

    private function title(): string
    {
        return $this->rung.'-day reminder · '.$this->invoice->number;
    }

    private function summary(): string
    {
        $parts = [
            Money::ofMinor((int) $this->invoice->balance_minor, (string) $this->invoice->currency)->format(true),
            $this->daysLate.' day'.($this->daysLate === 1 ? '' : 's').' past due',
            $this->clientMessaged ? 'client reminded on WhatsApp' : 'client not messaged — '.$this->reason,
        ];

        return implode(' · ', $parts);
    }
}
