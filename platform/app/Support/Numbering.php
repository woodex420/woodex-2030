<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\System\Models\NumberSequence;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Allocates document numbers (WI-10100, WF-10050, RC-2026-0001, L-2026-000123) with a row lock,
 * so two users can never receive the same number (D-16, docs/04 §3.3).
 */
final class Numbering
{
    public function next(string $key): string
    {
        return DB::transaction(function () use ($key): string {
            /** @var NumberSequence|null $sequence */
            $sequence = NumberSequence::query()->where('key', $key)->lockForUpdate()->first();

            if ($sequence === null) {
                throw new RuntimeException("Number sequence [{$key}] is not configured. Run the NumberSequenceSeeder.");
            }

            $year = now()->format('Y');

            if ($sequence->reset === 'yearly' && $sequence->period !== $year) {
                $sequence->next_number = 1;
                $sequence->period = $year;
            }

            $number = (int) $sequence->next_number;
            $sequence->next_number = $number + 1;
            $sequence->save();

            return self::format($sequence->format, $sequence->prefix, $year, $number, (int) $sequence->padding);
        });
    }

    /**
     * Preview the next number without allocating it (for UI hints only).
     */
    public function peek(string $key): ?string
    {
        $sequence = NumberSequence::query()->where('key', $key)->first();

        if ($sequence === null) {
            return null;
        }

        $year = now()->format('Y');
        $number = $sequence->reset === 'yearly' && $sequence->period !== $year ? 1 : (int) $sequence->next_number;

        return self::format($sequence->format, $sequence->prefix, $year, $number, (int) $sequence->padding);
    }

    public static function format(string $format, string $prefix, string $year, int $number, int $padding): string
    {
        return strtr($format, [
            '{prefix}' => $prefix,
            '{year}' => $year,
            '{number}' => str_pad((string) $number, $padding, '0', STR_PAD_LEFT),
        ]);
    }
}
