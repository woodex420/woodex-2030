<?php

declare(strict_types=1);

namespace App\Domain\Automation\Enums;

use App\Support\Concerns\EnumHelpers;

/** Where an enrolment stands (AUT-001/AUT-005). Paused holds its place; cancelled is the "stop, now" button. */
enum EnrolmentStatus: string
{
    use EnumHelpers;

    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Exited = 'exited';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Running',
            self::Paused => 'Paused',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Exited => 'Exited',
        };
    }
}
