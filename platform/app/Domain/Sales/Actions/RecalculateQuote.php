<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Data\LineInput;
use App\Domain\Sales\Data\QuoteTotals;
use App\Domain\Sales\Exceptions\QuoteLocked;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\QuoteItem;
use App\Domain\Sales\Models\QuoteMilestone;
use App\Domain\Sales\Models\QuoteSection;
use App\Domain\Sales\Services\MilestonePlan;
use App\Domain\Sales\Services\QuoteCalculator;
use App\Domain\Sales\Services\QuoteWriteLock;
use App\Settings\SalesSettings;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Writes the server-side totals of a quote (QUO-004): line discounts and totals, section subtotals, quote totals,
 * margin and milestone amounts. Runs after every change in the builder; only changed rows are saved.
 */
final class RecalculateQuote
{
    public function __construct(
        private readonly QuoteCalculator $calculator,
        private readonly SalesSettings $settings,
    ) {}

    public function handle(Quote $quote): QuoteTotals
    {
        return DB::transaction(function () use ($quote): QuoteTotals {
            QuoteWriteLock::refresh($quote);
            if (! $quote->isEditable()) {
                throw QuoteLocked::for($quote);
            }
            $sections = $quote->sections()->with('items')->get();

            $input = $sections->map(fn (QuoteSection $section): array => $section->items->map(fn (QuoteItem $item): LineInput => new LineInput(
                qty: (string) $item->qty,
                rateMinor: (int) $item->rate_minor,
                discountType: $item->discount_type,
                discountValue: (int) $item->discount_value,
                isOptional: (bool) $item->is_optional,
                internalCostMinor: $item->internal_cost_minor,
            ))->values()->all())->values()->all();

            $totals = $this->calculator->calculate(
                sections: $input,
                discountType: $quote->discount_type,
                discountValue: (int) $quote->discount_value,
                otherChargesMinor: (int) $quote->other_charges_minor + (int) $quote->shipping_minor,
                taxBp: (int) $quote->tax_bp,
                roundToRupee: (int) ($quote->meta['document_design']['furniture_revision'] ?? 1) >= 2 ? false : $this->settings->round_totals_to_rupee,
            );

            foreach ($sections->values() as $s => $section) {
                foreach ($section->items->values() as $i => $item) {
                    $item->discount_minor = $totals->lines[$s][$i]['discount'];
                    $item->line_total_minor = $totals->lines[$s][$i]['total'];
                    $item->save();
                }

                $section->subtotal_minor = $totals->sectionSubtotals[$s];
                $section->save();
            }

            $quote->fill([
                'subtotal_minor' => $totals->subtotal,
                'discount_minor' => $totals->discount,
                'tax_minor' => $totals->tax,
                'rounding_minor' => $totals->rounding,
                'total_minor' => $totals->total,
                'internal_cost_minor' => $totals->internalCost,
                'margin_bp' => $totals->marginBp,
            ])->save();

            $this->splitMilestones($quote, Money::ofMinor($totals->total));

            return $totals;
        });
    }

    /**
     * Valid plans (100 %) use MilestonePlan::split, where the last milestone absorbs rounding. While a custom plan
     * is being edited and does not add up yet, each row shows its plain percentage of the total.
     */
    private function splitMilestones(Quote $quote, Money $total): void
    {
        $milestones = $quote->milestones()->get();

        if ($milestones->isEmpty()) {
            return;
        }

        if ((int) $milestones->sum('percent_bp') === 10000) {
            $split = MilestonePlan::split($total, $milestones->map(fn (QuoteMilestone $milestone): array => [
                'name' => $milestone->name,
                'percent_bp' => $milestone->percent_bp,
                'trigger' => $milestone->trigger,
            ])->values()->all());

            foreach ($milestones->values() as $index => $milestone) {
                $milestone->amount_minor = $split[$index]['amount_minor'];
                $milestone->save();
            }

            return;
        }

        foreach ($milestones as $milestone) {
            $milestone->amount_minor = $total->percent($milestone->percent_bp)->minor;
            $milestone->save();
        }
    }
}
