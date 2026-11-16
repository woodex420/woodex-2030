<?php

declare(strict_types=1);

namespace App\Domain\Crm\Enums;

use App\Support\Concerns\EnumHelpers;

enum VisitStatus: string
{
    use EnumHelpers;

    case Scheduled = 'scheduled';
    case Confirmed = 'confirmed';
    case Rescheduled = 'rescheduled';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';

    public function color(): string
    {
        return match ($this) {
            self::Scheduled => 'info',
            self::Confirmed => 'navy',
            self::Rescheduled => 'warning',
            self::Completed => 'success',
            self::NoShow => 'error',
            self::Cancelled => 'gray',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Scheduled, self::Confirmed, self::Rescheduled], true);
    }
}
