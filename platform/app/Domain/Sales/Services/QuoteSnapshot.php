<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Sales\Enums\DiscountType;
use App\Domain\Sales\Enums\Unit;
use App\Domain\Sales\Models\BankAccount;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\QuoteItem;
use App\Domain\Sales\Models\QuoteMilestone;
use App\Domain\Sales\Models\QuoteSection;
use App\Settings\BusinessSettings;
use App\Settings\SalesSettings;
use App\Support\AmountInWords;
use App\Support\Html;
use App\Support\Money;
use Illuminate\Support\Facades\Storage;

/**
 * The client-facing content of a quotation, frozen at send time (D-15, QUO-008). The PDF, the client page and the
 * revision diff all read this array, never the live (editable) quote. Internal costs and margins are not included:
 * clients see snapshots.
 */
final class QuoteSnapshot
{
    public const int SCHEMA = 1;

    public function __construct(
        private readonly BusinessSettings $business,
        private readonly SalesSettings $sales,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Quote $quote, ?int $revision = null): array
    {
        $quote->loadMissing(['sections.items', 'milestones', 'company', 'owner:id,name', 'contact' => fn ($query) => $query->withTrashed()]);
        $contact = $quote->contact;
        $furniture = $quote->business_line === BusinessLine::Furniture;

        $discountLabel = 'Discount';
        if ($quote->discount_type === DiscountType::Percent && $quote->discount_value > 0) {
            $discountLabel .= ' ('.rtrim(rtrim(number_format($quote->discount_value / 100, 2), '0'), '.').'%)';
        }

        // Covered area for the project box: a section counts only when every priced line is
        // sft-measured — the same rule the summary-of-cost table uses.
        $areaSft = 0.0;
        foreach ($quote->sections as $section) {
            $priced = $section->items->filter(static fn (QuoteItem $item): bool => ! $item->is_optional);
            if ($priced->isNotEmpty() && $priced->every(static fn (QuoteItem $item): bool => Unit::normalize((string) $item->unit) === Unit::Sft)) {
                $areaSft += (float) $priced->sum(static fn (QuoteItem $item): float => (float) $item->qty);
            }
        }

        return [
            'schema' => self::SCHEMA,
            'quotation_renderer' => 'v21',
            'interior_revision' => (int) ($quote->meta['document_design']['interior_revision'] ?? 1),
            'furniture_revision' => (int) ($quote->meta['document_design']['furniture_revision'] ?? 1),
            'quote_layout' => DocumentStyles::quoteLayout($quote->meta['document_design']['quote_layout'] ?? ($furniture ? 'furniture' : 'classic')),
            'document_design' => $quote->meta['document_design'] ?? null,
            'number' => $quote->number,
            'revision' => $revision ?? (int) $quote->revision,
            'label' => $quote->number.(($revision ?? $quote->revision) > 0 ? ' R'.($revision ?? $quote->revision) : '')
                .($quote->option_label !== '' && $quote->option_label !== null ? ' · '.$quote->option_label : ''),
            'furniture' => FurnitureDocument::text($quote->meta['furniture'] ?? null),
            'title' => $quote->title,
            'type' => $quote->type,
            'site' => $quote->site,
            'intro_line' => $quote->intro_line,
            'area_sft' => $areaSft > 0 ? (int) round($areaSft) : null,
            'business_line' => $quote->business_line->value,
            'issued_on' => now()->toDateString(),
            'valid_until' => $quote->valid_until?->toDateString(),
            'currency' => $quote->currency,
            // Builder "as printed" overrides (Slice S) win over the contact for this document only.
            'client' => (function () use ($quote, $contact): array {
                $override = is_array($quote->meta['client_override'] ?? null) ? $quote->meta['client_override'] : [];
                $pick = static fn (string $key, ?string $fallback): ?string => trim((string) ($override[$key] ?? '')) !== '' ? (string) $override[$key] : $fallback;

                return [
                    'name' => $pick('name', $contact->name),
                    'company' => $pick('company', $quote->company->name ?? $contact->company?->name),
                    'phone' => $pick('phone', $contact->phone),
                    'email' => $pick('email', $contact->email),
                    'address' => $pick('address', trim(implode(', ', array_filter([$contact->area ?? null, $contact->city ?? null])))),
                ];
            })(),
            'scope_html' => Html::sanitize($quote->scope_html),
            'sections' => $quote->sections->map(fn (QuoteSection $section): array => [
                'name' => $section->name,
                'note' => $section->note,
                'subtotal_minor' => (int) $section->subtotal_minor,
                'items' => $section->items->map(fn (QuoteItem $item): array => [
                    'product_name' => (string) $item->product_name,
                    'description' => $item->description,
                    'image' => self::imageDataUri($item->image_path),
                    'unit' => $item->unit,
                    'unit_label' => Unit::normalize($item->unit)->pdfLabel(),
                    'qty' => (string) $item->qty,
                    'rate_minor' => (int) $item->rate_minor,
                    'discount_minor' => (int) $item->discount_minor,
                    'line_total_minor' => (int) $item->line_total_minor,
                    'is_optional' => (bool) $item->is_optional,
                ])->values()->all(),
            ])->values()->all(),
            'totals' => [
                'subtotal_minor' => (int) $quote->subtotal_minor,
                'discount_label' => $discountLabel,
                'discount_minor' => (int) $quote->discount_minor,
                'other_charges_label' => $quote->other_charges_label ?: 'Other charges',
                'other_charges_minor' => (int) $quote->other_charges_minor,
                'shipping_minor' => (int) $quote->shipping_minor,
                'advance_bp' => (int) $quote->advance_bp,
                'advance_minor' => Money::ofMinor((int) $quote->total_minor)->percent((int) $quote->advance_bp)->minor,
                'after_advance_minor' => (int) $quote->total_minor - Money::ofMinor((int) $quote->total_minor)->percent((int) $quote->advance_bp)->minor,
                'tax_bp' => (int) $quote->tax_bp,
                'tax_minor' => (int) $quote->tax_minor,
                'rounding_minor' => (int) $quote->rounding_minor,
                'total_minor' => (int) $quote->total_minor,
                'total_words' => AmountInWords::rupees(Money::ofMinor((int) $quote->total_minor)),
            ],
            'milestones' => $quote->milestones->map(fn (QuoteMilestone $milestone): array => [
                'name' => $milestone->name,
                'percent_bp' => (int) $milestone->percent_bp,
                'amount_minor' => (int) $milestone->amount_minor,
            ])->values()->all(),
            'terms' => $furniture && (int) ($quote->meta['document_design']['furniture_revision'] ?? 1) >= 2 ? FurnitureDocument::terms($quote->terms, (int) $quote->advance_bp) : array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $quote->terms) ?: []))),
            'notes' => trim((string) $quote->notes),
            'company' => [
                'brand' => $furniture ? $this->business->furniture_brand_name : $this->business->brand_name,
                'legal_name' => $this->business->legal_name,
                'tagline' => $this->business->tagline,
                'address' => self::withCity($this->business->address, $this->business->city),
                'phone' => $this->business->phone,
                'whatsapp' => $this->business->whatsapp,
                'email' => $this->business->email,
                'website' => $this->business->website,
                'ntn' => $this->business->ntn,
            ],
            'bank_accounts' => BankAccount::query()->forLine($quote->business_line)->where('kind', 'bank')->limit(3)->get()
                ->map(fn (BankAccount $account): array => [
                    'title' => $account->title,
                    'bank_name' => $account->bank_name,
                    'account_number' => $account->account_number,
                    'iban' => $account->iban,
                    'branch' => $account->branch,
                    'branch_code' => $account->branch_code,
                ])->values()->all(),
            'wallets' => BankAccount::query()->forLine($quote->business_line)->where('kind', 'wallet')->limit(3)->get()
                ->map(fn (BankAccount $account): array => [
                    'provider' => $account->wallet_provider ?: $account->bank_name,
                    'number' => $account->account_number,
                ])->values()->all(),
            'signatories' => array_values($quote->signatories ?: $this->sales->signatories),
            'prepared_by' => $quote->owner?->name,
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

    /**
     * The item picture travels inside the snapshot as a data URI: a frozen, sent PDF must render
     * from its own bytes years later, without the media file still being in place.
     */
    private static function imageDataUri(?string $path): ?string
    {
        if ($path === null || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $mime = str_ends_with($path, '.png') ? 'image/png' : (str_ends_with($path, '.webp') ? 'image/webp' : 'image/jpeg');

        return 'data:'.$mime.';base64,'.base64_encode((string) Storage::disk('public')->get($path));
    }
}
