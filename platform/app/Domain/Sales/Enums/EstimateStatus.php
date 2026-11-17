<?php

declare(strict_types=1);

namespace App\Domain\Sales\Enums;

use App\Support\Concerns\EnumHelpers;

enum EstimateStatus: string
{
    use EnumHelpers;

    case Draft = 'draft';
    case Reviewed = 'reviewed';
    case Converted = 'converted';
}
