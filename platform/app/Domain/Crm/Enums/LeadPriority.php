<?php

declare(strict_types=1);

namespace App\Domain\Crm\Enums;

use App\Support\Concerns\EnumHelpers;

enum LeadPriority: string
{
    use EnumHelpers;

    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';

    public function color(): string
    {
        return match ($this) {
            self::Low => 'gray',
            self::Normal => 'info',
            self::High => 'error',
        };
    }
}
