<?php

declare(strict_types=1);

namespace App\Integrations\Webhooks;

use App\Domain\Crm\Events\LeadCaptured;
use App\Domain\Sales\Events\InvoicePaid;
use App\Domain\Sales\Events\QuoteSent;
use App\Support\Money;

/**
 * Translates the app's own moments into webhook payloads. Registered next to the other listeners
 * in AppServiceProvider; with no endpoints configured every handler is a no-op query.
 */
final class WebhookEventListener
{
    public function __construct(private readonly WebhookDispatcher $webhooks) {}

    public function handleLeadCaptured(LeadCaptured $event): void
    {
        if (! $event->isNewLead) {
            return; // a returning enquiry joining an open lead is not a new lead anywhere else either
        }

        $lead = $event->lead->loadMissing(['contact', 'stage']);

        $this->webhooks->dispatch(WebhookEvents::LEAD_CREATED, [
            'ref' => $lead->ref,
            'name' => $lead->contact->name,
            'phone' => $lead->contact->phone,
            'email' => $lead->contact->email,
            'source' => $lead->source->value,
            'business_line' => $lead->business_line->value,
            'service' => $lead->service,
            'city' => $lead->city,
            'area' => $lead->area,
            'stage' => $lead->stage?->name,
            'created_at' => $lead->created_at?->toIso8601String(),
        ]);
    }

    public function handleQuoteSent(QuoteSent $event): void
    {
        $quote = $event->quote->loadMissing('contact');

        $this->webhooks->dispatch(WebhookEvents::QUOTE_SENT, [
            'number' => $quote->number,
            'revision' => (int) $event->version->revision,
            'contact' => $quote->contact->name,
            'business_line' => $quote->business_line->value,
            'total_minor' => (int) $quote->total_minor,
            'total' => Money::ofMinor((int) $quote->total_minor)->format(),
            'channel' => $event->channel,
            'sent_at' => $event->version->sent_at?->toIso8601String(),
        ]);
    }

    public function handleInvoicePaid(InvoicePaid $event): void
    {
        $invoice = $event->invoice->loadMissing('contact');

        $this->webhooks->dispatch(WebhookEvents::INVOICE_PAID, [
            'number' => $invoice->number,
            'contact' => $invoice->contact->name,
            'business_line' => $invoice->business_line->value,
            'total_minor' => (int) $invoice->total_minor,
            'total' => Money::ofMinor((int) $invoice->total_minor, $invoice->currency)->format(),
            'payment_minor' => (int) $event->payment->amount_minor,
            'method' => $event->payment->method->value,
            'paid_at' => $event->payment->verified_at?->toIso8601String() ?? now()->toIso8601String(),
        ]);
    }
}
