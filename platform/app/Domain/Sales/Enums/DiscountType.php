<?php

declare(strict_types=1);

namespace App\Domain\Sales\Enums;

use App\Support\Concerns\EnumHelpers;

enum DiscountType: string
{
    use EnumHelpers;

    case Percent = 'percent';
    case Amount = 'amount';
}
