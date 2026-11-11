<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use Illuminate\Validation\ValidationException;

/** Do not turn negative/exponent/garbage input into a different, billable quantity. */
final class EstimateQuantity
{
    public static function parse(string $raw, string $field = 'quantity'): float
    {
        $raw = trim($raw);
        if (! preg_match('/^(?:\d+|\d{1,3}(?:,\d{3})+)(?:\.\d{1,3})?$/D', $raw)) {
            throw ValidationException::withMessages([$field => 'Enter a positive number, with at most three decimal places.']);
        }
        $value = (float) str_replace(',', '', $raw);
        if (! is_finite($value) || $value <= 0 || $value > 10000000) {
            throw ValidationException::withMessages([$field => 'Quantity must be greater than zero and at most 10,000,000.']);
        }

        return $value;
    }
}
