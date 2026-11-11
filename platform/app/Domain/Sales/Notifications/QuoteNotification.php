<?php

declare(strict_types=1);

namespace App\Domain\Sales\Notifications;

use App\Domain\Sales\Models\Quote;
use App\Integrations\Telegram\TelegramMessage;
use App\Notifications\Concerns\SendsToDatabaseAndTelegram;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Quotation alerts for staff (QUO-007/010): approval requested / decided, first view, hot (3+ views in 24 h),
 * accepted, changes requested. Bell always, Telegram when linked.
 */
final class QuoteNotification extends Notification implements ShouldQueue
{
    use Queueable, SendsToDatabaseAndTelegram;

    private const array TITLES = [
        'approval_requested' => ['🟡', 'Approval needed'],
        'approved' => ['✅', 'Quotation approved'],
        'rejected' => ['↩️', 'Approval declined'],
        'first_view' => ['👀', 'Quotation opened'],
        'hot' => ['🔥', 'Hot quotation'],
        'accepted' => ['🎉', 'Quotation accepted'],
        'changes_requested' => ['✏️', 'Changes requested'],
        'acceptance_suggested' => ['🤔', 'Client may have accepted'],
        'follow_up' => ['📞', 'Quotation not answered'],
        'expiry_warning' => ['⏳', 'Quotation expiring'],
        'expired' => ['⌛', 'Quotation lapsed'],
    ];

    public function __construct(
        public readonly Quote $quote,
        public readonly string $kind,
        public readonly string $detail = '',
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'quote.'.$this->kind,
            'title' => self::TITLES[$this->kind][1] ?? 'Quotation',
            'body' => $this->summary(),
            'quote_id' => $this->quote->id,
            'url' => $this->kind === 'approval_requested' ? route('admin.approvals') : route('admin.quotes.show', $this->quote),
        ];
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        [$icon, $title] = self::TITLES[$this->kind] ?? ['📄', 'Quotation'];

        return new TelegramMessage(
            $icon.' <b>'.TelegramMessage::escape($title).'</b> · '.TelegramMessage::escape($this->quote->label())."\n".TelegramMessage::escape($this->summary()),
            $this->openButton('Open quotation', route('admin.quotes.show', $this->quote)),
        );
    }

    private function summary(): string
    {
        $quote = $this->quote;
        $base = $quote->contact->name.' · '.Money::ofMinor((int) $quote->total_minor)->format(true).' · '.$quote->title;

        return $this->detail !== '' ? $base."\n".$this->detail : $base;
    }
}
