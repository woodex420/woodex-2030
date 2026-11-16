<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Sales\Data\LineInput;
use App\Domain\Sales\Enums\DiscountType;
use App\Domain\Sales\Enums\Unit;
use App\Domain\Sales\Models\Quote;
use App\Settings\SalesSettings;
use App\Support\Html;
use App\Support\Money;
use App\Support\NumberInput;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/** A read-only projection of the editor. Never saves a model or changes a sent snapshot. */
final class QuoteDraftPreview
{
    /** @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    public function build(Quote $original, array $form): array
    {
        Validator::make($form, [
            'lines' => ['sometimes', 'array', 'max:1000'],
            'lines.*' => ['array'],
            'lines.*.*' => ['nullable', 'max:2000', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_scalar($value)) {
                    $fail('Line values must be text, numbers or booleans.');
                }
            }],
            'lines.*.is_optional' => ['sometimes', 'boolean'],
            'sections' => ['sometimes', 'array', 'max:200'],
            'sections.*' => ['array'],
            'sections.*.*' => ['nullable', 'string', 'max:1000'],
            'details' => ['sometimes', 'array'],
            'details.*' => ['nullable', 'string', 'max:20000'],
            'details.valid_until' => ['nullable', 'date_format:Y-m-d'],
            'client' => ['sometimes', 'array'],
            'client.*' => ['nullable', 'string', 'max:300'],
            'milestones' => ['sometimes', 'array', 'max:100'],
            'milestones.*' => ['array'],
            'milestones.*.*' => ['nullable', 'string', 'max:160'],
            'pdfTheme' => ['sometimes', 'string', 'in:classic,minimal,premium'],
            'quoteLayout' => ['sometimes', 'string', 'in:classic,single,project,furniture'],
            'lines.*.product_name' => ['nullable', 'string', 'max:160'],
        ])->validate();

        $original->loadMissing(['sections.items', 'milestones']);
        $quote = clone $original;
        $sections = $original->sections->map(function ($originalSection) use ($form) {
            $section = clone $originalSection;
            $state = $form['sections'][$section->id] ?? [];
            foreach (['name', 'note'] as $field) {
                if (array_key_exists($field, $state)) {
                    $section->{$field} = (string) $state[$field];
                }
            }
            $section->setRelation('items', $originalSection->items->map(function ($originalItem) use ($form) {
                $item = clone $originalItem;
                $state = $form['lines'][$item->id] ?? [];
                foreach (['product_name', 'description', 'is_optional'] as $field) {
                    if (array_key_exists($field, $state)) {
                        $item->{$field} = $state[$field];
                    }
                }
                if (isset($state['unit'])) {
                    $item->unit = Unit::tryFrom((string) $state['unit'])->value ?? throw new InvalidArgumentException('Choose a valid unit.');
                }
                if (isset($state['qty'])) {
                    $item->qty = NumberInput::quantity((string) $state['qty']);
                }
                if (isset($state['rate'])) {
                    $item->rate_minor = NumberInput::rupees((string) $state['rate']);
                }
                if (isset($state['discount_type'])) {
                    $item->discount_type = DiscountType::tryFrom((string) $state['discount_type']);
                }
                if (isset($state['discount_value'])) {
                    $item->discount_value = $this->discount($item->discount_type, (string) $state['discount_value']);
                }

                return $item;
            }));

            return $section;
        });
        $quote->setRelation('sections', $sections);
        $details = $form['details'] ?? [];
        foreach (['title', 'type', 'site', 'intro_line', 'valid_until', 'terms', 'notes', 'other_charges_label'] as $field) {
            if (array_key_exists($field, $details)) {
                $quote->{$field} = $details[$field] === '' && $field === 'valid_until' ? null : $details[$field];
            }
        }
        if (isset($details['scope'])) {
            $quote->scope_html = Html::fromPlainText((string) $details['scope']);
        }
        if (isset($details['discount_type'])) {
            $quote->discount_type = DiscountType::tryFrom((string) $details['discount_type']);
        }
        if (isset($details['discount_value'])) {
            $quote->discount_value = $this->discount($quote->discount_type, (string) $details['discount_value']);
        }
        if (isset($details['tax'])) {
            $quote->tax_bp = $details['tax'] === '' ? 0 : NumberInput::percent((string) $details['tax']);
        }
        if (isset($details['other_charges'])) {
            $quote->other_charges_minor = $details['other_charges'] === '' ? 0 : NumberInput::rupees((string) $details['other_charges']);
        }
        if (isset($details['shipping'])) {
            $quote->shipping_minor = $details['shipping'] === '' ? 0 : NumberInput::rupees((string) $details['shipping']);
        }
        if (isset($details['advance'])) {
            $quote->advance_bp = $details['advance'] === '' ? 0 : NumberInput::percent((string) $details['advance']);
        }
        $text = FurnitureDocument::validatedText(array_intersect_key($details, FurnitureDocument::text(null)));
        $quote->meta = [...($quote->meta ?? []), 'furniture' => [...FurnitureDocument::text($quote->meta['furniture'] ?? null), ...array_intersect_key($text, $details)]];
        $design = $quote->meta['document_design'] ?? [];
        $design['pdf_theme'] = DocumentStyles::theme($form['pdfTheme'] ?? $design['pdf_theme'] ?? null);
        $design['quote_layout'] = DocumentStyles::quoteLayout($form['quoteLayout'] ?? $design['quote_layout'] ?? null);
        $client = array_intersect_key($form['client'] ?? [], array_flip(['name', 'company', 'phone', 'email', 'address']));
        $quote->meta = [...($quote->meta ?? []), 'document_design' => $design, 'client_override' => [...($quote->meta['client_override'] ?? []), ...$client]];

        $inputs = $sections->map(fn ($section) => $section->items->map(fn ($item) => new LineInput((string) $item->qty, $item->rate_minor, $item->discount_type, $item->discount_value, $item->is_optional))->all())->all();
        $totals = app(QuoteCalculator::class)->calculate($inputs, $quote->discount_type, $quote->discount_value, $quote->other_charges_minor + $quote->shipping_minor, $quote->tax_bp, (int) ($quote->meta['document_design']['furniture_revision'] ?? 1) >= 2 ? false : app(SalesSettings::class)->round_totals_to_rupee);
        foreach ($sections as $si => $section) {
            $section->subtotal_minor = $totals->sectionSubtotals[$si];
            foreach ($section->items as $ii => $item) {
                $item->discount_minor = $totals->lines[$si][$ii]['discount'];
                $item->line_total_minor = $totals->lines[$si][$ii]['total'];
            }
        }
        foreach (['subtotal_minor' => $totals->subtotal, 'discount_minor' => $totals->discount, 'tax_minor' => $totals->tax, 'rounding_minor' => $totals->rounding, 'total_minor' => $totals->total] as $field => $value) {
            $quote->{$field} = $value;
        }
        $quote->setRelation('milestones', $original->milestones->map(function ($originalMilestone) use ($form, $totals) {
            $milestone = clone $originalMilestone;
            $state = $form['milestones'][$milestone->id] ?? [];
            $milestone->name = $state['name'] ?? $milestone->name;
            if (isset($state['percent'])) {
                $milestone->percent_bp = NumberInput::percent((string) $state['percent']);
            }
            $milestone->amount_minor = Money::ofMinor($totals->total)->percent($milestone->percent_bp)->minor;

            return $milestone;
        }));

        if ($quote->milestones->isNotEmpty() && (int) $quote->milestones->sum('percent_bp') === 10000) {
            $split = MilestonePlan::split(Money::ofMinor($totals->total), $quote->milestones->map(fn ($milestone): array => ['name' => $milestone->name, 'percent_bp' => $milestone->percent_bp])->values()->all());
            foreach ($quote->milestones->values() as $index => $milestone) {
                $milestone->amount_minor = $split[$index]['amount_minor'];
            }
        }
        $snapshot = app(QuoteSnapshot::class)->build($quote);
        if (isset($details['signed_by'])) {
            $options = array_values(array_filter(app(SalesSettings::class)->signatories, static fn ($signer): bool => is_array($signer) && trim((string) ($signer['name'] ?? '')) !== ''));
            $index = (string) $details['signed_by'];
            $snapshot['signatories'] = ctype_digit($index) && isset($options[(int) $index]) ? [$options[(int) $index]] : $options;
        }

        return $snapshot;
    }

    private function discount(?DiscountType $type, string $raw): int
    {
        return $type === null || $raw === '' ? 0 : ($type === DiscountType::Percent ? NumberInput::percent($raw) : NumberInput::rupees($raw));
    }
}
