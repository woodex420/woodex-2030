<?php

declare(strict_types=1);

namespace App\Domain\Crm\Enums;

use App\Support\Concerns\EnumHelpers;

enum ClientType: string
{
    use EnumHelpers;

    case Individual = 'individual';
    case Company = 'company';
    case Developer = 'developer';
    case Architect = 'architect';
}
