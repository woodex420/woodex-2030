<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Sales\Data\LineInput;
use App\Domain\Sales\Data\QuoteTotals;
use App\Domain\Sales\Enums\DiscountType;
use App\Support\Money;

/**
 * The ONLY place where quotation totals are calculated (D-14, QUO-004).
 *
 *   line gross   = round_half_up(qty × rate)
 *   line total   = gross − line discount (percent of gross, or fixed amount capped at gross)
 *   subtotal     = Σ non-optional line totals
 *   discount     = percent of subtotal, or fixed amount capped at subtotal
 *   taxable      = subtotal − discount + other charges (rent / transport, QUO-020)
 *   tax          = round_half_up(taxable × tax_bp / 10000)
 *   total        = taxable + tax, optionally rounded to whole rupees (rounding shown separately)
 */
final class QuoteCalculator
{
    /**
     * @param  list<list<LineInput>>  $sections
     */
    public function calculate(
        array $sections,
        ?DiscountType $discountType = null,
        int $discountValue = 0,
        int $otherChargesMinor = 0,
        int $taxBp = 0,
        bool $roundToRupee = true,
    ): QuoteTotals {
        $lines = [];
        $sectionSubtotals = [];
        $subtotal = Money::zero();
        $internalCost = 0;
        $hasCost = false;

        foreach ($sections as $sectionLines) {
            $sectionResult = [];
            $sectionTotal = Money::zero();

            foreach ($sectionLines as $line) {
                $gross = Money::ofMinor($line->rateMinor)->times($line->qty);
                $discount = $this->discount($gross, $line->discountType, $line->discountValue);
                $total = $gross->minus($discount);

                $sectionResult[] = ['gross' => $gross->minor, 'discount' => $discount->minor, 'total' => $total->minor];

                if (! $line->isOptional) {
                    $sectionTotal = $sectionTotal->plus($total);

                    if ($line->internalCostMinor !== null) {
                        $hasCost = true;
                        $internalCost += Money::ofMinor($line->internalCostMinor)->times($line->qty)->minor;
                    }
                }
            }

            $lines[] = $sectionResult;
            $sectionSubtotals[] = $sectionTotal->minor;
            $subtotal = $subtotal->plus($sectionTotal);
        }

        $quoteDiscount = $this->discount($subtotal, $discountType, $discountValue);
        $taxable = $subtotal->minus($quoteDiscount)->plus(Money::ofMinor(max(0, $otherChargesMinor)));
        $tax = $taxBp > 0 ? $taxable->percent($taxBp) : Money::zero();
        $preRounding = $taxable->plus($tax);
        $total = $roundToRupee ? $preRounding->roundToRupee() : $preRounding;

        $revenue = $subtotal->minus($quoteDiscount)->minor;
        $marginBp = $hasCost && $revenue > 0
            ? intdiv(($revenue - $internalCost) * 10000, $revenue)
            : null;

        return new QuoteTotals(
            lines: $lines,
            sectionSubtotals: $sectionSubtotals,
            subtotal: $subtotal->minor,
            discount: $quoteDiscount->minor,
            otherCharges: max(0, $otherChargesMinor),
            taxable: $taxable->minor,
            tax: $tax->minor,
            rounding: $total->minor - $preRounding->minor,
            total: $total->minor,
            internalCost: $internalCost,
            marginBp: $marginBp,
        );
    }

    private function discount(Money $base, ?DiscountType $type, int $value): Money
    {
        if ($type === null || $value <= 0 || $base->minor <= 0) {
            return Money::zero();
        }

        return match ($type) {
            DiscountType::Percent => $base->percent(min($value, 10000)),
            DiscountType::Amount => Money::ofMinor($value)->min($base),
        };
    }
}
