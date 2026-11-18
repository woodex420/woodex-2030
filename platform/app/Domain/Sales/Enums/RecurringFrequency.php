<?php

declare(strict_types=1);

namespace App\Domain\Sales\Enums;

use App\Support\Concerns\EnumHelpers;
use Carbon\CarbonImmutable;

/**
 * How often a recurring invoice repeats. The next date is computed from the previous one (never from "today"),
 * so a monthly schedule on the 1st stays on the 1st and a missed run does not drift the series.
 */
enum RecurringFrequency: string
{
    use EnumHelpers;

    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';

    public function advance(CarbonImmutable $from): CarbonImmutable
    {
        return match ($this) {
            self::Monthly => $from->addMonthNoOverflow(),
            self::Quarterly => $from->addMonthsNoOverflow(3),
            self::Yearly => $from->addYearNoOverflow(),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Monthly => 'every month',
            self::Quarterly => 'every quarter',
            self::Yearly => 'every year',
        };
    }
}
