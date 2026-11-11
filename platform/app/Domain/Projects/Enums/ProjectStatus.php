<?php

declare(strict_types=1);

namespace App\Domain\Projects\Enums;

use App\Support\Concerns\EnumHelpers;

enum ProjectStatus: string
{
    use EnumHelpers;

    case Active = 'active';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::OnHold => 'On hold',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    /** @return 'success'|'warning'|'navy'|'error' */
    public function color(): string
    {
        return match ($this) {
            self::Active => 'navy',
            self::OnHold => 'warning',
            self::Completed => 'success',
            self::Cancelled => 'error',
        };
    }
}
