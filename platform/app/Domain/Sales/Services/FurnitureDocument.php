<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

/** Safe furniture presentation and commercial text; never accepts arbitrary CSS or HTML. */
final class FurnitureDocument
{
    public const OPTIONS = ['show_discount' => 'Discount row (including zero)', 'show_charges' => 'Rent / Transport row (including zero)', 'show_tax' => 'Tax row (including zero)', 'show_advance' => 'Advance and remainder', 'show_terms' => 'Terms and conditions', 'show_notes' => 'Additional notes', 'show_delivery' => 'Delivery note', 'show_transport' => 'Transportation note', 'show_signatories' => 'Centred signatories'];

    /** @param list<array<string, mixed>> $people */
    public static function signatureText(array $people): string
    {
        return implode("\n", array_map(static fn (array $person): string => (string) ($person['name'] ?? '').' | '.(string) ($person['title'] ?? ''), array_slice($people, 0, 6)));
    }

    /** @return list<array{name: string, title: string}> */
    public static function signatories(string $text): array
    {
        $people = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            [$name, $title] = array_pad(explode('|', $line, 2), 2, '');
            if (count($people) >= 6) {
                break;
            }
            if (trim($name) !== '') {
                $people[] = ['name' => mb_substr(trim($name), 0, 160), 'title' => mb_substr(trim($title), 0, 160)];
            }
        }

        return $people;
    }

    /** One percentage supplies both the amount and the payment sentence; custom non-payment terms survive.
     * @return list<string>
     */
    public static function terms(?string $text, int $advanceBp): array
    {
        $percent = rtrim(rtrim(number_format($advanceBp / 100, 2, '.', ''), '0'), '.');
        $terms = [$percent.'% advance payment with work order.'];
        foreach (preg_split('/\R/', (string) $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^(?:payment(?:\s+terms)?\s*[:–-]?\s*)?\d+(?:\.\d+)?\s*%\s+(?:advance|upfront)\b/i', $line) || str_starts_with($line, 'Advance requested and remainder are shown')) {
                continue;
            }
            $terms[] = $line;
        }

        return $terms;
    }

    /** Six default commercial rows, with custom conditions retained and dates/percentages from the snapshot.
     * @param  array<string, mixed>  $snapshot
     * @return list<array{string, string}>
     */
    public static function commercialTerms(array $snapshot): array
    {
        $text = self::text($snapshot['furniture'] ?? null);
        $terms = [];
        $validityAdded = false;
        $validity = empty($snapshot['valid_until']) ? 'No expiry date specified.'
            : 'Valid until '.CarbonImmutable::parse($snapshot['valid_until'])->format('d M Y').'.';
        foreach ($snapshot['terms'] ?? [] as $index => $term) {
            $label = $index === 0 ? 'Payment' : match ($term) {
                'Materials, finish and fabric are subject to client approval.' => 'Materials',
                'Delivery starts after advance confirmation and material approval.' => 'Delivery commencement',
                'Quotation validity is shown above.' => 'Validity',
                default => 'Additional condition',
            };
            if ($term === 'Quotation validity is shown above.') {
                $term = $validity;
                $validityAdded = true;
            }
            $terms[] = [$label, $term];
        }
        if (! $validityAdded) {
            $terms[] = ['Validity', $validity];
        }
        if ($text['show_delivery'] === 'yes' && $text['delivery_text'] !== '') {
            $terms[] = ['Delivery time', $text['delivery_text']];
        }
        if ($text['show_transport'] === 'yes' && $text['transport_text'] !== '') {
            $terms[] = ['Transportation', $text['transport_text']];
        }

        return $terms;
    }

    public const TERMS = "Materials, finish and fabric are subject to client approval.\nDelivery starts after advance confirmation and material approval.\nQuotation validity is shown above.";

    /** @return array<string, string> */
    public static function style(mixed $value): array
    {
        $value = is_array($value) ? $value : [];
        $defaults = ['use_default_logo' => 'yes', 'header_align' => 'center', 'footer_align' => 'center', 'secondary_color' => '#c8ccca', 'quotation_title' => 'Quotation', 'invoice_title' => 'Invoice'];
        foreach ($defaults as $key => $default) {
            $candidate = $value[$key] ?? null;
            if (! is_string($candidate)) {
                continue;
            }
            $valid = $key === 'use_default_logo' ? in_array($candidate, ['yes', 'no'], true) : (str_ends_with($key, '_align') ? in_array($candidate, ['left', 'center', 'right'], true)
                : ($key === 'secondary_color' ? preg_match('/^#[0-9a-fA-F]{6}$/', $candidate) === 1 : mb_strlen($candidate) <= 60 && trim($candidate) !== ''));
            if ($valid) {
                $defaults[$key] = trim($candidate);
            }
        }

        return $defaults;
    }

    /** @return array<string, string> */
    public static function text(mixed $value): array
    {
        $value = is_array($value) ? $value : [];
        $defaults = ['document_title' => '', 'delivery_text' => '', 'transport_text' => 'Outstation charges depend on city, quantity and load.', 'tax_note' => '', 'po_reference' => '', 'signatories_text' => '', 'show_discount' => 'no', 'show_charges' => 'no', 'show_tax' => 'no', 'show_advance' => 'yes', 'show_terms' => 'yes', 'show_notes' => 'yes', 'show_delivery' => 'yes', 'show_transport' => 'yes', 'show_signatories' => 'yes'];
        foreach ($defaults as $key => $default) {
            if (is_string($value[$key] ?? null)) {
                $defaults[$key] = isset(self::OPTIONS[$key]) ? (in_array($value[$key], ['yes', 'no'], true) ? $value[$key] : $default) : mb_substr(trim($value[$key]), 0, $key === 'signatories_text' ? 2000 : ($key === 'document_title' ? 60 : 300));
            }
        }

        return $defaults;
    }

    /** @param array<string, mixed> $value
     * @return array<string, string>
     */
    public static function validatedText(array $value): array
    {
        $rules = ['signatories_text' => ['nullable', 'string', 'max:2000']];
        foreach (array_keys(self::OPTIONS) as $key) {
            $rules[$key] = ['sometimes', 'in:yes,no'];
        }
        Validator::make($value, [...$rules, 'document_title' => ['nullable', 'string', 'max:60'], 'delivery_text' => ['nullable', 'string', 'max:300'], 'transport_text' => ['nullable', 'string', 'max:300'], 'tax_note' => ['nullable', 'string', 'max:300'], 'po_reference' => ['nullable', 'string', 'max:300']])->validate();

        return self::text($value);
    }
}
