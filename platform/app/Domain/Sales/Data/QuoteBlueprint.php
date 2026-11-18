<?php

declare(strict_types=1);

namespace App\Domain\Sales\Data;

use App\Domain\Sales\Enums\Unit;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\QuoteItem;
use App\Domain\Sales\Models\QuoteSection;
use App\Support\NumberInput;
use InvalidArgumentException;

/**
 * The sections + items part of a template (quote_templates.blueprint, docs/04 §5.3), converted to and from quotes.
 * Template quantities or rates left blank ("to be measured") become 0, so the builder shows them as still to price.
 */
final class QuoteBlueprint
{
    /**
     * Rows ready for quote_sections / quote_items from a template blueprint.
     *
     * @param  array<string, mixed>|null  $blueprint
     * @return list<array{name: string, note: string|null, items: list<array{product_name: string, description: string, unit: string, qty: string, rate_minor: int, catalog_item_id: int|null, is_optional: bool, internal_cost_minor: int|null}>}>
     */
    public static function sections(?array $blueprint): array
    {
        $sections = [];

        foreach ((array) ($blueprint['sections'] ?? []) as $section) {
            $items = [];

            foreach ((array) ($section['items'] ?? []) as $item) {
                $items[] = [
                    'product_name' => mb_substr(trim((string) ($item['product_name'] ?? '')), 0, 160),
                    'description' => trim((string) ($item['description'] ?? '')),
                    'unit' => Unit::normalize(isset($item['unit']) ? (string) $item['unit'] : null)->value,
                    'qty' => self::quantity($item['qty'] ?? null),
                    'rate_minor' => max(0, (int) ($item['rate_minor'] ?? 0)),
                    'catalog_item_id' => isset($item['catalog_item_id']) ? (int) $item['catalog_item_id'] : null,
                    'is_optional' => (bool) ($item['is_optional'] ?? false),
                    'internal_cost_minor' => isset($item['internal_cost_minor']) ? max(0, (int) $item['internal_cost_minor']) : null,
                ];
            }

            $sections[] = [
                'name' => trim((string) ($section['name'] ?? '')) ?: 'Section',
                'note' => trim((string) ($section['note'] ?? '')) ?: null,
                'items' => $items,
            ];
        }

        return $sections;
    }

    /**
     * Blueprint of a quote (for "Save as template"). Line discounts stay with the quote.
     *
     * @return array{sections: list<array<string, mixed>>}
     */
    public static function fromQuote(Quote $quote): array
    {
        $quote->loadMissing('sections.items');

        return ['sections' => $quote->sections->map(fn (QuoteSection $section): array => [
            'name' => $section->name,
            'note' => (string) $section->note,
            'items' => $section->items->map(fn (QuoteItem $item): array => [
                'product_name' => (string) $item->product_name,
                'description' => $item->description,
                'unit' => $item->unit,
                'qty' => NumberInput::fromQuantity((string) $item->qty),
                'rate_minor' => (int) $item->rate_minor,
                'catalog_item_id' => $item->catalog_item_id,
                'is_optional' => (bool) $item->is_optional,
                'internal_cost_minor' => $item->internal_cost_minor,
            ])->values()->all(),
        ])->values()->all()];
    }

    private static function quantity(mixed $qty): string
    {
        if ($qty === null || $qty === '') {
            return '0';
        }

        try {
            return NumberInput::quantity((string) $qty);
        } catch (InvalidArgumentException) {
            return '0';
        }
    }
}
