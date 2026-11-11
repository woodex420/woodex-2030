<?php

declare(strict_types=1);

namespace App\Domain\Crm\Enums;

use App\Support\Concerns\EnumHelpers;

enum TaskStatus: string
{
    use EnumHelpers;

    case Open = 'open';
    case Done = 'done';
    case Cancelled = 'cancelled';
}
