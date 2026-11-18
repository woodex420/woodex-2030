<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Puts what happens to an invoice or a payment on the client's timeline: on the contact and, where the sale came
 * from an enquiry, on that lead. LogQuoteEvent does the same for a quotation.
 *
 * The timeline has always offered "Invoice" and "Payment" as filters, but nothing wrote those two kinds: the record
 * of a sale stopped at "accepted", and the sales person who won it could not see on their lead whether the invoice
 * had gone out or the advance had come in (T-080).
 */
final class LogBillingActivity
{
    public function invoice(Invoice $invoice, string $event, ?User $by, string $text): void
    {
        $this->write($invoice->contact_id, $this->leadOf($invoice), $by, $invoice, ActivityType::InvoiceEvent, $text, [
            'invoice_event' => $event,
            'invoice' => $invoice->number,
        ]);
    }

    public function payment(Payment $payment, string $event, ?User $by, string $text): void
    {
        $invoiceId = $payment->invoice_id ?? $payment->allocations()->orderBy('id')->value('invoice_id');
        $invoice = $invoiceId === null ? null : Invoice::query()->find($invoiceId);

        $this->write($payment->contact_id, $invoice === null ? null : $this->leadOf($invoice), $by, $payment, ActivityType::Payment, $text, [
            'payment_event' => $event,
            'invoice' => $invoice?->number,
            'receipt' => $payment->receipt_number,
        ]);
    }

    /**
     * The invoices a payment went against, with where each now stands: "WFI-2026-0001 (part paid)". No amounts: the
     * timeline is read by everybody who may open the lead, the figures only by those who may open the invoice.
     */
    public function against(Payment $payment): string
    {
        $invoices = $payment->allocations()->with('invoice:id,number,balance_minor')->orderBy('id')->get()
            ->map(static fn ($allocation): ?string => $allocation->invoice === null ? null
                : $allocation->invoice->number.((int) $allocation->invoice->balance_minor > 0 ? ' (part paid)' : ' (paid in full)'))
            ->filter()->unique()->values();

        return $invoices->isEmpty() ? 'kept as credit for the client' : 'against '.$invoices->implode(', ');
    }

    private function leadOf(Invoice $invoice): ?int
    {
        $invoice->loadMissing(['quote:id,lead_id', 'project:id,lead_id']);

        return $invoice->quote?->lead_id ?? $invoice->project?->lead_id;
    }

    /** @param  array<string, mixed>  $data */
    private function write(?int $contactId, ?int $leadId, ?User $by, Model $subject, ActivityType $type, string $text, array $data): void
    {
        if ($contactId === null) {
            return;
        }

        $now = now();

        Activity::create([
            'contact_id' => $contactId,
            'lead_id' => $leadId,
            'user_id' => $by?->id,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'type' => $type,
            'body' => $text,
            'data' => array_filter($data, static fn ($value) => $value !== null),
            'occurred_at' => $now,
        ]);

        if ($leadId !== null) {
            Lead::query()->whereKey($leadId)->update(['last_activity_at' => $now]);
        }

        Contact::query()->whereKey($contactId)->update(['last_activity_at' => $now]);
    }
}
