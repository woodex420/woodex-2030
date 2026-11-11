<?php

declare(strict_types=1);

namespace App\Domain\Sales\Data;

/**
 * Result of QuoteCalculator. All amounts in paisa.
 */
final readonly class QuoteTotals
{
    /**
     * @param  list<list<array{gross: int, discount: int, total: int}>>  $lines  per section, per line
     * @param  list<int>  $sectionSubtotals
     */
    public function __construct(
        public array $lines,
        public array $sectionSubtotals,
        public int $subtotal,
        public int $discount,
        public int $otherCharges,
        public int $taxable,
        public int $tax,
        public int $rounding,
        public int $total,
        public int $internalCost,
        public ?int $marginBp,
    ) {}
}
