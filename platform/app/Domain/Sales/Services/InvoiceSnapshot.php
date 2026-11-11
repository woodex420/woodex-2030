<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Sales\Enums\PaymentStatus;
use App\Domain\Sales\Enums\Unit;
use App\Domain\Sales\Models\BankAccount;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\InvoiceItem;
use App\Domain\Sales\Models\Payment;
use App\Settings\BusinessSettings;
use App\Settings\SalesSettings;
use App\Support\AmountInWords;
use App\Support\Html;
use App\Support\Money;

/**
 * The client-facing content of an invoice, frozen when it is issued (INV-003).
 *
 * The PDF, the client's payment link and the reminder messages all read this array and never the live invoice, so
 * editing a bank account or a rate later cannot change a document that has already been sent.
 */
final class InvoiceSnapshot
{
    public const int SCHEMA = 1;

    public function __construct(private readonly BusinessSettings $business) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Invoice $invoice): array
    {
        $invoice->loadMissing(['items', 'contact', 'company', 'bankAccount', 'project', 'quote', 'payments']);

        $business = [
            'name' => $invoice->business_line === BusinessLine::Furniture ? $this->business->furniture_brand_name : $this->business->brand_name,
            'legal_name' => $this->business->legal_name,
            'address' => self::withCity($this->business->address, $this->business->city),
            'city' => $this->business->city,
            'phone' => $this->business->phone,
            'email' => $this->business->email,
            'website' => $this->business->website,
            'ntn' => $this->business->ntn,
            'tagline' => $this->business->tagline,
            'whatsapp' => $this->business->whatsapp,
        ];

        $bank = $invoice->bankAccount;
        $quoteVersion = $invoice->quote?->versions()->reorder('revision', 'desc')->first();
        $documentDesign = $quoteVersion !== null
            ? ($quoteVersion->snapshot['document_design'] ?? null)
            : ($invoice->quote !== null ? ($invoice->quote->meta['document_design'] ?? null) : app(DocumentStyles::class)->copy($invoice->business_line));

        $client = [
            'name' => $invoice->contact->name,
            'company' => $invoice->company?->name,
            'phone' => $invoice->contact->phone,
            'email' => $invoice->contact->email,
            'address' => $invoice->contact->address,
            'city' => $invoice->contact->city,
        ];

        $commercial = $invoice->commercial ?? [];
        if (($commercial['renderer'] ?? '') === 'furniture-v1') {
            $documentDesign = $commercial['document_design'] ?? $documentDesign;
            $client = [...$client, ...($commercial['client'] ?? [])];
        }

        $items = $invoice->items
            ->sortBy('position')
            ->values()
            ->map(fn (InvoiceItem $item): array => [
                'product_name' => (string) $item->product_name,
                'description' => $item->description,
                'unit' => $item->unit,
                'unit_label' => Unit::normalize((string) $item->unit)->pdfLabel(),
                'qty' => (float) $item->qty,
                'rate' => Money::ofMinor((int) $item->rate_minor, $invoice->currency)->format(),
                'discount' => (int) $item->discount_minor,
                'tax_bp' => (int) $item->tax_bp,
                'line_total' => Money::ofMinor((int) $item->line_total_minor, $invoice->currency)->format(),
            ])->all();

        $total = Money::ofMinor((int) $invoice->total_minor, $invoice->currency);

        $wallets = BankAccount::query()->forLine($invoice->business_line)->where('kind', 'wallet')
            ->when($bank !== null, fn ($query) => $query->whereKeyNot($bank->id))->limit(3)->get()
            ->map(fn (BankAccount $account): array => [
                'provider' => $account->wallet_provider ?: $account->bank_name,
                'number' => $account->account_number,
            ])->values()->all();

        // Payments received (verified only) + the Paid / Part paid / Unpaid stamp, as on the v2.1 invoice.
        $payments = $invoice->payments
            ->where('status', PaymentStatus::Verified)
            ->sortBy([['received_on', 'asc'], ['id', 'asc']])
            ->map(fn (Payment $payment): array => [
                'receipt' => (string) $payment->receipt_number,
                'date' => $payment->received_on?->format('j M Y'),
                'method' => $payment->method->label(),
                'reference' => (string) $payment->reference,
                'amount' => Money::ofMinor((int) $payment->amount_minor, $invoice->currency)->format(),
            ])->values()->all();

        $payStamp = match (true) {
            (int) $invoice->balance_minor === 0 && (int) $invoice->paid_minor > 0 => ['label' => 'Paid', 'tone' => 'paid'],
            (int) $invoice->paid_minor > 0 => ['label' => 'Part paid', 'tone' => 'part'],
            default => ['label' => 'Unpaid', 'tone' => 'due'],
        };

        $signatory = app(SalesSettings::class)->signatories[0] ?? null;

        return [
            'schema' => self::SCHEMA,
            'furniture_revision' => (int) ($documentDesign['furniture_revision'] ?? 1),
            'after_requested_advance' => Money::ofMinor(max(0, (int) $invoice->total_minor - (int) round((int) $invoice->total_minor * (int) $invoice->advance_bp / 10000)), $invoice->currency)->format(),
            'furniture_amounts' => ['discount' => (int) $invoice->discount_minor, 'charges' => (int) $invoice->rent_minor + (int) $invoice->shipping_minor, 'tax' => (int) $invoice->tax_minor, 'rounding' => (int) ($commercial['rounding_minor'] ?? 0)],
            'furniture_renderer' => $commercial['renderer'] ?? null,
            'furniture' => FurnitureDocument::text($commercial['furniture'] ?? null),
            'scope_html' => Html::sanitize($commercial['scope_html'] ?? null),
            'signatories' => $commercial['signatories'] ?? null,
            'source_number' => $commercial['source_number'] ?? null,
            'rent' => Money::ofMinor((int) $invoice->rent_minor, $invoice->currency)->format(),
            'shipping' => Money::ofMinor((int) $invoice->shipping_minor, $invoice->currency)->format(),
            'rounding' => Money::ofMinor((int) ($commercial['rounding_minor'] ?? 0), $invoice->currency)->format(),
            'advance_percent' => rtrim(rtrim(number_format((int) $invoice->advance_bp / 100, 2, '.', ''), '0'), '.'),
            'advance' => Money::ofMinor((int) $invoice->total_minor, $invoice->currency)->percent((int) $invoice->advance_bp)->format(),
            'document_design' => $documentDesign,
            'number' => $invoice->number,
            'kind' => $invoice->kind->value,
            'status' => $invoice->status->value,
            'currency' => $invoice->currency,
            'issue_date' => $invoice->issue_date?->format('j M Y'),
            'due_date' => $invoice->due_date?->format('j M Y'),
            'business' => $business,
            'client' => $client,
            'bank' => $bank === null ? null : [
                'title' => $bank->title,
                'bank_name' => $bank->bank_name,
                'account_number' => $bank->account_number,
                'iban' => $bank->iban,
                'branch' => $bank->branch,
                'branch_code' => $bank->branch_code,
                'wallet_provider' => $bank->wallet_provider,
                'kind' => $bank->kind,
            ],
            'wallets' => $wallets,
            'project' => $invoice->project === null ? null : [
                'code' => $invoice->project->code,
                'name' => $invoice->project->name,
            ],
            'quote' => $invoice->quote === null ? null : [
                'number' => $invoice->quote->number,
                'title' => $invoice->quote->title,
                'site' => $invoice->quote->site,
                'total' => Money::ofMinor((int) $invoice->quote->total_minor, $invoice->currency)->format(),
            ],
            'items' => $items,
            'payments' => $payments,
            'pay_stamp' => $payStamp,
            'signatory' => is_array($signatory) ? ['name' => (string) ($signatory['name'] ?? ''), 'title' => (string) ($signatory['title'] ?? '')] : null,
            'subtotal' => Money::ofMinor((int) $invoice->subtotal_minor, $invoice->currency)->format(),
            'discount' => Money::ofMinor((int) $invoice->discount_minor, $invoice->currency)->format(),
            'tax' => Money::ofMinor((int) $invoice->tax_minor, $invoice->currency)->format(),
            'tax_bp' => (int) $invoice->items->max('tax_bp'),
            'total' => $total->format(),
            'paid' => Money::ofMinor((int) $invoice->paid_minor, $invoice->currency)->format(),
            'balance' => Money::ofMinor((int) $invoice->balance_minor, $invoice->currency)->format(),
            'total_in_words' => AmountInWords::rupees($total),
            'notes' => $invoice->notes,
            'terms' => ($commercial['renderer'] ?? '') === 'furniture-v1' && (int) ($documentDesign['furniture_revision'] ?? 1) >= 2 ? implode("\n", FurnitureDocument::terms($invoice->terms, (int) $invoice->advance_bp)) : $invoice->terms,
        ];
    }

    /** "…, Lahore" + city "Lahore" stays "…, Lahore" (the settings address often ends with the city already). */
    private static function withCity(string $address, string $city): string
    {
        $address = trim($address, " ,\t");
        $city = trim($city);

        if ($city === '' || preg_match('/(^|[\s,])'.preg_quote($city, '/').'$/iu', $address) === 1) {
            return $address;
        }

        return trim($address.', '.$city, ', ');
    }
}
