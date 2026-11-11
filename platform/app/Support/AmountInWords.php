<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Amount in words for invoices/quotations (GLB-012).
 *
 * South Asian system (default): "Rupees Four Lakh Fifty Thousand Only".
 * International system:         "Rupees Four Hundred Fifty Thousand Only".
 */
final class AmountInWords
{
    private const ONES = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen',
    ];

    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    public static function rupees(Money $money, bool $southAsian = true): string
    {
        $absolute = abs($money->minor);
        $rupees = intdiv($absolute, 100);
        $paisa = $absolute % 100;

        $words = $rupees === 0 ? 'Zero' : ($southAsian ? self::southAsian($rupees) : self::international($rupees));
        $text = 'Rupees '.$words;

        if ($paisa > 0) {
            $text .= ' and '.self::belowHundred($paisa).' Paisa';
        }

        return ($money->minor < 0 ? 'Minus ' : '').$text.' Only';
    }

    public static function southAsian(int $number): string
    {
        if ($number === 0) {
            return 'Zero';
        }

        $parts = [];

        $crore = intdiv($number, 10_000_000);
        $number %= 10_000_000;

        if ($crore > 0) {
            // Very large amounts: "One Hundred Twenty Crore" (crore of crores is not used in documents).
            $parts[] = self::southAsian($crore).' Crore';
        }

        $lakh = intdiv($number, 100_000);
        $number %= 100_000;

        if ($lakh > 0) {
            $parts[] = self::belowHundred($lakh).' Lakh';
        }

        $thousand = intdiv($number, 1000);
        $number %= 1000;

        if ($thousand > 0) {
            $parts[] = self::belowHundred($thousand).' Thousand';
        }

        if ($number > 0) {
            $parts[] = self::belowThousand($number);
        }

        return implode(' ', $parts);
    }

    public static function international(int $number): string
    {
        if ($number === 0) {
            return 'Zero';
        }

        $scales = [
            1_000_000_000_000 => 'Trillion',
            1_000_000_000 => 'Billion',
            1_000_000 => 'Million',
            1000 => 'Thousand',
        ];

        $parts = [];

        foreach ($scales as $value => $name) {
            $chunk = intdiv($number, $value);

            if ($chunk > 0) {
                $parts[] = self::international($chunk).' '.$name;
                $number %= $value;
            }
        }

        if ($number > 0) {
            $parts[] = self::belowThousand($number);
        }

        return implode(' ', $parts);
    }

    private static function belowThousand(int $number): string
    {
        $hundreds = intdiv($number, 100);
        $rest = $number % 100;
        $parts = [];

        if ($hundreds > 0) {
            $parts[] = self::ONES[$hundreds].' Hundred';
        }

        if ($rest > 0) {
            $parts[] = self::belowHundred($rest);
        }

        return implode(' ', $parts);
    }

    private static function belowHundred(int $number): string
    {
        if ($number < 20) {
            return self::ONES[$number];
        }

        $tens = intdiv($number, 10);
        $ones = $number % 10;

        return trim(self::TENS[$tens].($ones > 0 ? ' '.self::ONES[$ones] : ''));
    }
}
