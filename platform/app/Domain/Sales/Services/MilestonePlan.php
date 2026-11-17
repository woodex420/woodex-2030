<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Sales\Enums\MilestonePlanKey;
use App\Support\Money;
use InvalidArgumentException;

/**
 * Payment milestone plans (QUO-005). Percentages are basis points and must total 10000;
 * the last milestone absorbs rounding so the amounts always add up to the quotation total.
 */
final class MilestonePlan
{
    /**
     * @return list<array{name: string, percent_bp: int, trigger: string}>
     */
    public static function definition(MilestonePlanKey $key): array
    {
        return match ($key) {
            MilestonePlanKey::Execution => [
                ['name' => 'Advance with work order', 'percent_bp' => 5000, 'trigger' => 'on_acceptance'],
                ['name' => 'On completion of civil and wood work', 'percent_bp' => 4000, 'trigger' => 'on_stage:execution'],
                ['name' => 'On handover', 'percent_bp' => 1000, 'trigger' => 'on_stage:handover'],
            ],
            MilestonePlanKey::Design => [
                ['name' => 'Advance with work order', 'percent_bp' => 7500, 'trigger' => 'on_acceptance'],
                ['name' => 'On approval of project', 'percent_bp' => 2500, 'trigger' => 'on_approval'],
            ],
            MilestonePlanKey::Custom => [
                ['name' => 'Full payment', 'percent_bp' => 10000, 'trigger' => 'on_acceptance'],
            ],
        };
    }

    /**
     * Split a total into milestone amounts. Payable amounts are whole rupees (paisa are not used in payments): every
     * milestone but the last is rounded to the nearest rupee and the last takes the rest, so the amounts always add
     * up to the total. Only for absurdly small totals, where rounding would leave the last one negative, the split is
     * exact to the paisa.
     *
     * @param  list<array{name: string, percent_bp: int, trigger?: string}>  $milestones
     * @return list<array{name: string, percent_bp: int, trigger: string, amount_minor: int}>
     */
    public static function split(Money $total, array $milestones): array
    {
        if ($milestones === []) {
            throw new InvalidArgumentException('At least one milestone is required.');
        }

        $sum = array_sum(array_column($milestones, 'percent_bp'));

        if ($sum !== 10000) {
            throw new InvalidArgumentException("Milestone percentages must total 100% (got {$sum} bp).");
        }

        $amounts = self::amounts($total, $milestones, wholeRupees: true);

        if (min($amounts) < 0) {
            $amounts = self::amounts($total, $milestones, wholeRupees: false);
        }

        return array_map(fn (array $milestone, int $amount): array => [
            'name' => $milestone['name'],
            'percent_bp' => $milestone['percent_bp'],
            'trigger' => $milestone['trigger'] ?? 'manual',
            'amount_minor' => $amount,
        ], array_values($milestones), $amounts);
    }

    /**
     * @param  list<array{name: string, percent_bp: int, trigger?: string}>  $milestones
     * @return list<int>
     */
    private static function amounts(Money $total, array $milestones, bool $wholeRupees): array
    {
        $amounts = [];
        $allocated = 0;
        $last = count($milestones) - 1;

        foreach (array_values($milestones) as $index => $milestone) {
            $share = $total->percent($milestone['percent_bp'])->minor;

            if ($wholeRupees) {
                $share = $share >= 0 ? intdiv($share + 50, 100) * 100 : -intdiv(-$share + 50, 100) * 100;
            }

            $amount = $index === $last ? $total->minor - $allocated : $share;
            $allocated += $amount;
            $amounts[] = $amount;
        }

        return $amounts;
    }
}
