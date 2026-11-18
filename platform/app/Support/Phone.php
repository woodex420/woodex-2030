<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Phone helpers. Numbers are stored in E.164 (+923224000768). Phone is the main dedupe key (D-30).
 *
 * Accepted inputs (Pakistan default): 0322-4000768, 03224000768, 3224000768, 923224000768,
 * +92 322 4000768, 0092 322 4000768, +92 (0) 322 4000768, Lahore landline 042 35761234,
 * and any international number written with + or 00.
 */
final class Phone
{
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $raw = trim($input);

        if ($raw === '') {
            return null;
        }

        $hasPlus = str_starts_with($raw, '+');
        $digits = (string) preg_replace('/\D+/', '', $raw);

        if ($digits === '') {
            return null;
        }

        $length = strlen($digits);

        if ($hasPlus) {
            $e164 = '+'.$digits;
        } elseif (str_starts_with($digits, '00')) {
            $e164 = '+'.substr($digits, 2);
        } elseif (str_starts_with($digits, '92') && $length >= 11 && $length <= 13) {
            $e164 = '+'.$digits;
        } elseif (str_starts_with($digits, '0') && $length >= 10 && $length <= 11) {
            $e164 = '+92'.substr($digits, 1);
        } elseif (str_starts_with($digits, '3') && $length === 10) {
            $e164 = '+92'.$digits;
        } else {
            return null;
        }

        // "+92 (0) 322…" style: drop the trunk zero after the country code.
        if (str_starts_with($e164, '+920')) {
            $e164 = '+92'.substr($e164, 4);
        }

        $significant = strlen($e164) - 1;

        if ($significant < 8 || $significant > 15) {
            return null;
        }

        // Pakistani mobiles are always +92 3XX XXXXXXX (12 digits after "+").
        if (str_starts_with($e164, '+923') && strlen($e164) !== 13) {
            return null;
        }

        return $e164;
    }

    public static function isValid(?string $input): bool
    {
        return self::normalize($input) !== null;
    }

    /**
     * Friendly display: +92 322 4000768 for Pakistani mobiles, otherwise the E.164 value.
     */
    public static function display(?string $e164): string
    {
        if ($e164 === null || $e164 === '') {
            return '';
        }

        if (str_starts_with($e164, '+923') && strlen($e164) === 13) {
            return '+92 '.substr($e164, 3, 3).' '.substr($e164, 6);
        }

        return $e164;
    }

    public static function digits(?string $e164): string
    {
        return (string) preg_replace('/\D+/', '', (string) $e164);
    }

    public static function whatsappUrl(?string $e164, ?string $text = null): ?string
    {
        $digits = self::digits($e164);

        if ($digits === '') {
            return null;
        }

        return 'https://wa.me/'.$digits.($text !== null && $text !== '' ? '?text='.rawurlencode($text) : '');
    }

    public static function telUrl(?string $e164): ?string
    {
        return $e164 ? 'tel:'.$e164 : null;
    }
}
