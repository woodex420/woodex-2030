<?php

declare(strict_types=1);

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Parses and prints the numbers people type into forms (quantities, rupee amounts, percentages).
 * Values travel as strings and are converted exactly, never through floats (D-14).
 *
 *   quantity("1,355.5")  → "1355.5"      rupees("1,200.50") → 120050 (paisa)
 *   percent("2.5")       → 250 (bp)      fromMinor(120050)  → "1200.50"
 */
final class NumberInput
{
    /**
     * A non-negative quantity with at most 3 decimals, normalised ("0012.50" → "12.5").
     */
    public static function quantity(string $value): string
    {
        $clean = self::clean($value);

        if ($clean === '' || ! preg_match('/^\d{1,11}(\.\d{0,3})?$/', $clean)) {
            throw new InvalidArgumentException('Enter a quantity such as 12 or 121.125 (up to 3 decimals).');
        }

        return self::trimDecimal(BigDecimal::of(rtrim($clean, '.'))->toScale(3)->__toString());
    }

    /**
     * A non-negative rupee amount (up to 2 decimals) in paisa.
     */
    public static function rupees(string $value, int $maxRupees = 9_999_999_999): int
    {
        $clean = self::clean(str_ireplace(['rs.', 'rs', 'pkr'], '', $value));

        if ($clean === '' || ! preg_match('/^\d+(\.\d{0,2})?$/', $clean)) {
            throw new InvalidArgumentException('Enter an amount in rupees such as 1200 or 1,200.50.');
        }

        try {
            $amount = BigDecimal::of(rtrim($clean, '.'));
        } catch (MathException) {
            throw new InvalidArgumentException('Enter an amount in rupees such as 1200 or 1,200.50.');
        }

        if ($amount->isGreaterThan($maxRupees)) {
            throw new InvalidArgumentException('That amount is too large.');
        }

        return $amount->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    /**
     * A percentage (up to 2 decimals) in basis points, e.g. "2.5" → 250.
     */
    public static function percent(string $value, int $maxBp = 10_000): int
    {
        $clean = self::clean(str_replace('%', '', $value));

        if ($clean === '' || ! preg_match('/^\d{1,5}(\.\d{0,2})?$/', $clean)) {
            throw new InvalidArgumentException('Enter a percentage such as 10 or 2.5.');
        }

        $bp = BigDecimal::of(rtrim($clean, '.'))->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();

        if ($bp > $maxBp) {
            throw new InvalidArgumentException('The percentage cannot be more than '.self::fromBp($maxBp).'%.');
        }

        return $bp;
    }

    /**
     * Paisa → editable rupee string without grouping: 120000 → "1200", 120050 → "1200.50".
     */
    public static function fromMinor(int $minor): string
    {
        $value = BigDecimal::ofUnscaledValue($minor, 2);

        return $minor % 100 === 0 ? (string) intdiv($minor, 100) : $value->__toString();
    }

    /**
     * Basis points → editable percentage: 250 → "2.5", 1000 → "10".
     */
    public static function fromBp(int $bp): string
    {
        return self::trimDecimal(BigDecimal::ofUnscaledValue($bp, 2)->__toString());
    }

    /**
     * Stored decimal quantity → display/edit string: "121.125", "1355.000" → "1355".
     */
    public static function fromQuantity(string|int|float|null $qty): string
    {
        if ($qty === null || $qty === '') {
            return '0';
        }

        return self::trimDecimal(BigDecimal::of((string) $qty)->toScale(3, RoundingMode::HalfUp)->__toString());
    }

    private static function clean(string $value): string
    {
        return str_replace([',', ' ', "\u{00A0}"], '', trim($value));
    }

    private static function trimDecimal(string $decimal): string
    {
        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }
}
