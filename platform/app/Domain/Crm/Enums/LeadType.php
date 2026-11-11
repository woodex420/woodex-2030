<?php

declare(strict_types=1);

namespace App\Domain\Crm\Enums;

use App\Support\Concerns\EnumHelpers;

enum LeadType: string
{
    use EnumHelpers;

    case New = 'new';
    case Returning = 'returning';
    case Referral = 'referral';
}
