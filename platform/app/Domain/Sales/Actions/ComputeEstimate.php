<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Models\CatalogItem;
use App\Domain\Sales\Models\EstimatorRate;
use App\Domain\Sales\Services\EstimateQuantity;
use Illuminate\Validation\ValidationException;

/**
 * The one server-side pricing engine for estimates (P8.3, docs/19 §7): the same math for the
 * admin builder, the saved snapshot and the conversion — never a second browser-side calculator.
 * The headline uses the chosen finish at the rate book's current rate; the honest band runs from
 * essential to premium so nobody presents one number as a promise. Contingency is explicit,
 * in basis points, and shown — never hidden in a rate.
 */
final class ComputeEstimate
{
    private const FINISHES = ['essential', 'standard', 'premium'];

    /**
     * @param  list<array{catalog_item_id: int|string, qty: string|int|float, note?: string|null}>  $catalogLines
     * @return array{effective_on: string, rate_minor: int, headline_minor: int, range_low_minor: int, range_high_minor: int, lines: list<array{catalog_item_id: int, title: string, unit: string, qty: string, rate_minor: int, total_minor: int}>, lines_minor: int, contingency_minor: int, subtotal_minor: int, total_minor: int, quantity: string, unit: string, finish: string}
     */
    public function handle(EstimatorRate $rate, string $quantity, string $finish, array $catalogLines, int $contingencyBp): array
    {
        $quantityValue = EstimateQuantity::parse($quantity);
        $finish = in_array($finish, self::FINISHES, true) ? $finish : 'standard';
        $contingencyBp = max(0, min($contingencyBp, 10_000));

        if ($quantityValue <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Give the size or quantity the estimate is for.']);
        }

        $unit = (string) $rate->unit;
        $rateMinor = $rate->rateFor($finish)->minor;

        $lines = [];
        $linesMinor = 0;

        foreach ($catalogLines as $line) {
            $itemId = (int) ($line['catalog_item_id'] ?? 0);
            $qty = EstimateQuantity::parse((string) ($line['qty'] ?? '0'), 'catalogLines');

            if ($itemId <= 0 || $qty <= 0) {
                throw ValidationException::withMessages(['catalogLines' => 'Every rate book line needs an item and a positive quantity.']);
            }

            $item = CatalogItem::query()->whereKey($itemId)->where('is_active', true)->first();

            if ($item === null) {
                throw ValidationException::withMessages(['catalogLines' => 'A chosen rate book item is missing or inactive.']);
            }

            $lineMinor = (int) round($qty * (int) $item->rate_minor);
            $linesMinor += $lineMinor;

            $lines[] = [
                'catalog_item_id' => $item->id,
                'title' => (string) $item->description,
                'unit' => (string) $item->unit,
                'qty' => (string) $qty,
                'rate_minor' => (int) $item->rate_minor,
                'total_minor' => $lineMinor,
            ];
        }

        $headlineMinor = (int) round($rateMinor * $quantityValue);
        $subtotalMinor = $headlineMinor + $linesMinor;
        $contingencyMinor = (int) round($subtotalMinor * $contingencyBp / 10_000);

        return [
            'service_label' => $rate->label,
            'effective_on' => today()->toDateString(),
            'rate_minor' => $rateMinor,
            'headline_minor' => $headlineMinor,
            'range_low_minor' => (int) round((int) $rate->essential_minor * $quantityValue),
            'range_high_minor' => (int) round((int) $rate->premium_minor * $quantityValue),
            'lines' => $lines,
            'lines_minor' => $linesMinor,
            'contingency_minor' => $contingencyMinor,
            'subtotal_minor' => $subtotalMinor,
            'total_minor' => $subtotalMinor + $contingencyMinor,
            'quantity' => (string) $quantityValue,
            'unit' => $unit,
            'finish' => $finish,
        ];
    }
}
