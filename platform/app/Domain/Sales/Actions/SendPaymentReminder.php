<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Conversations\Actions\SendConversationMessage;
use App\Domain\Conversations\Enums\Channel;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Sales\Models\Invoice;
use App\Domain\System\Enums\WhatsAppTemplateEvent;
use App\Domain\System\Models\WhatsAppTemplate;
use App\Integrations\WhatsApp\Contracts\WhatsAppGateway;
use App\Models\User;
use App\Support\Money;
use Throwable;

/**
 * P8.2: a consent-gated payment reminder on WhatsApp (A0 law: nothing messages a client without
 * consent). In the 24-hour window the reminder goes as plain text; outside it only the studio's
 * approved PaymentReminder template may go, one body variable carrying the summary line.
 * The result is always a string the caller can show — a send never throws at the caller.
 */
final class SendPaymentReminder
{
    public function __construct(
        private readonly WhatsAppGateway $whatsapp,
        private readonly SendConversationMessage $send,
    ) {}

    /**
     * @param  User|null  $by  the person sending; null when the reminder ladder sends on the studio's behalf
     * @return array{sent: bool, reason: string}
     */
    public function handle(Invoice $invoice, ?User $by = null): array
    {
        $contact = $invoice->contact;

        if ($contact === null) {
            return $this->record($invoice, $by, false, 'the invoice has no client contact.');
        }

        $number = $contact->whatsappNumber();

        if ($number === null || trim($number) === '') {
            return $this->record($invoice, $by, false, 'the client has no phone number for WhatsApp.');
        }

        if ($contact->opted_out_at !== null || ! $contact->consent_whatsapp) {
            return $this->record($invoice, $by, false, 'the client has not consented to WhatsApp messages — call them instead.');
        }

        if (! $this->whatsapp->isConfigured()) {
            return $this->record($invoice, $by, false, 'WhatsApp is not connected in Settings yet.');
        }

        $conversation = Conversation::query()->firstOrCreate(
            ['contact_id' => $contact->id, 'channel' => Channel::Whatsapp->value],
            ['external_number' => $number, 'status' => ConversationStatus::Open, 'window_expires_at' => now()->addHours(24), 'unread_count' => 0],
        );

        $summary = sprintf('%s — %s outstanding, due %s.', $invoice->number, Money::ofMinor((int) $invoice->balance_minor)->format(), $invoice->due_date?->format('j M Y') ?? 'on request');

        try {
            $message = $conversation->isInWindow()
                ? $this->send->handle($conversation, "Payment reminder: {$summary}", $by)
                : $this->send->template($conversation, $this->reminderTemplate()['name'], [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $summary]]]], $this->reminderTemplate()['language'], $by);
        } catch (Throwable $exception) {
            return $this->record($invoice, $by, false, $exception->getMessage());
        }

        $failed = $message->status === MessageStatus::Failed;

        return $this->record($invoice, $by, ! $failed, $failed ? ($message->error_message ?? 'Meta did not accept the message.') : 'reminder sent on WhatsApp.');
    }

    /**
     * The studio's approved PaymentReminder template (mapped on the templates screen), one body
     * variable, like Meta's payment reminder pattern.
     *
     * @return array{name: string, language: string}
     */
    private function reminderTemplate(): array
    {
        $mapped = WhatsAppTemplate::forEvent(WhatsAppTemplateEvent::PaymentReminder);

        if ($mapped !== null && $mapped->name !== '') {
            return ['name' => $mapped->name, 'language' => $mapped->language !== '' ? $mapped->language : 'en'];
        }

        return ['name' => (string) config('services.whatsapp.payment_reminder_template', 'payment_reminder'), 'language' => 'en'];
    }

    /** @return array{sent: bool, reason: string} */
    private function record(Invoice $invoice, ?User $by, bool $sent, string $reason): array
    {
        activity('billing')
            ->causedBy($by)
            ->performedOn($invoice)
            ->withProperties(['invoice' => $invoice->number, 'channel' => 'whatsapp'])
            ->log($sent ? "Payment reminder sent to {$invoice->contact?->name}: {$reason}" : 'Payment reminder not sent: '.$reason);

        return ['sent' => $sent, 'reason' => $reason];
    }
}
