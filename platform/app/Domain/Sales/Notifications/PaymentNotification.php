<?php

declare(strict_types=1);

namespace App\Domain\Sales\Notifications;

use App\Domain\Sales\Models\Payment;
use App\Domain\System\Telegram\TelegramCallbackBus;
use App\Integrations\Telegram\TelegramMessage;
use App\Notifications\Concerns\SendsToDatabaseAndTelegram;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Money alerts for the team (INV-005/006): a client says they paid, the accountant confirmed it, or it was sent back.
 * Bell always, Telegram when linked. The client's copy goes by email (App\Domain\Sales\Mail\PaymentMail).
 */
final class PaymentNotification extends Notification implements ShouldQueue
{
    use Queueable, SendsToDatabaseAndTelegram;

    private const array TITLES = [
        'proof_submitted' => ['🧾', 'Payment proof to check'],
        'verified' => ['✅', 'Payment received'],
        'rejected' => ['↩️', 'Payment not matched'],
    ];

    public function __construct(
        public readonly Payment $payment,
        public readonly string $kind,
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'payment.'.$this->kind,
            'title' => self::TITLES[$this->kind][1] ?? 'Payment',
            'body' => $this->summary(),
            'url' => route('admin.payments.index', ['search' => (string) $this->payment->receipt_number]),
        ];
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        [$icon, $title] = self::TITLES[$this->kind] ?? ['💳', 'Payment'];

        $text = $icon.' <b>'.TelegramMessage::escape($title).'</b> · '.TelegramMessage::escape($this->payment->contact->name)."\n"
            .TelegramMessage::escape($this->summary());

        // A proof to check can be decided on the spot (TGM-005); everything else just links to the screen.
        if ($this->kind === 'proof_submitted') {
            return new TelegramMessage($text, [
                [
                    ['text' => 'Verify', 'callback_data' => TelegramCallbackBus::button('payverify', $this->payment->id)],
                    ['text' => 'Not matched', 'callback_data' => TelegramCallbackBus::button('payreject', $this->payment->id)],
                ],
                ...$this->openButton('Open payments', route('admin.payments.index')),
            ]);
        }

        return new TelegramMessage($text, $this->openButton('Open payments', route('admin.payments.index')));
    }

    private function summary(): string
    {
        $parts = [
            Money::ofMinor((int) $this->payment->amount_minor)->format(true),
            $this->payment->method->label(),
            $this->payment->received_on?->format('j M Y') ?? '—',
        ];

        if (filled($this->payment->reference)) {
            $parts[] = 'ref '.$this->payment->reference;
        }

        if ($this->kind === 'rejected' && filled($this->payment->rejected_reason)) {
            $parts[] = (string) $this->payment->rejected_reason;
        }

        return implode(' · ', $parts);
    }
}
