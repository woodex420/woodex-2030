<?php

declare(strict_types=1);

namespace App\Domain\Sales\Data;

use App\Domain\Sales\Enums\DiscountType;
use InvalidArgumentException;

/**
 * One BOQ line as entered in the builder. Quantity is a decimal string (max 3 decimals), never a float.
 */
final readonly class LineInput
{
    public function __construct(
        public string $qty,
        public int $rateMinor,
        public ?DiscountType $discountType = null,
        public int $discountValue = 0,
        public bool $isOptional = false,
        public ?int $internalCostMinor = null,
    ) {
        if (! preg_match('/^\d{1,11}(\.\d{1,3})?$/', $qty)) {
            throw new InvalidArgumentException("Invalid quantity [{$qty}]: use up to 3 decimals.");
        }

        if ($rateMinor < 0 || $discountValue < 0) {
            throw new InvalidArgumentException('Rates and discounts cannot be negative.');
        }

        if ($discountType === DiscountType::Percent && $discountValue > 10000) {
            throw new InvalidArgumentException('Percentage discount cannot exceed 100%.');
        }
    }
}
