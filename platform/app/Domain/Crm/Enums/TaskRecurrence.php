<?php

declare(strict_types=1);

namespace App\Domain\Crm\Enums;

use App\Support\Concerns\EnumHelpers;
use Carbon\CarbonInterface;

/**
 * Repeating tasks (CRM-007): completing one creates the next occurrence.
 */
enum TaskRecurrence: string
{
    use EnumHelpers;

    case Daily = 'daily';
    case Weekdays = 'weekdays';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Every day',
            self::Weekdays => 'Every working day (Mon–Sat)',
            self::Weekly => 'Every week',
            self::Monthly => 'Every month',
        };
    }

    public function next(CarbonInterface $from): CarbonInterface
    {
        $next = match ($this) {
            self::Daily, self::Weekdays => $from->copy()->addDay(),
            self::Weekly => $from->copy()->addWeek(),
            self::Monthly => $from->copy()->addMonthNoOverflow(),
        };

        // Woodex works Monday to Saturday: a weekday task never lands on Sunday.
        if ($this === self::Weekdays && $next->isSunday()) {
            $next = $next->addDay();
        }

        return $next;
    }
}
