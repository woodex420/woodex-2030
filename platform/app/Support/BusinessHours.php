<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Studio working hours (Settings → Business). Used for SLA due times (CRM-010) and quiet hours.
 * Pakistan has no daylight saving time, so Asia/Karachi arithmetic is unambiguous.
 */
final readonly class BusinessHours
{
    /**
     * @param  list<int>  $workingDays  ISO day numbers (1 = Monday … 7 = Sunday)
     * @param  list<string>  $holidays  closed dates (Y-m-d), Settings → Business (AUT-004)
     */
    public function __construct(
        public string $opensAt = '10:00',
        public string $closesAt = '19:00',
        public array $workingDays = [1, 2, 3, 4, 5, 6],
        public string $timezone = 'Asia/Karachi',
        public array $holidays = [],
    ) {}

    /**
     * The working days as the public footer says them: "Monday to Saturday", "Every day", or a list.
     * The footer used to spell out "Monday to Saturday" itself, so changing the setting changed nothing.
     */
    public function daysLabel(): string
    {
        $names = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

        $days = array_values(array_intersect(array_keys($names), $this->workingDays));
        sort($days);

        if ($days === []) {
            return 'By appointment';
        }

        if ($days === range(1, 7)) {
            return 'Every day';
        }

        if ($days === range($days[0], end($days))) {
            return count($days) === 1 ? $names[$days[0]] : $names[$days[0]].' to '.$names[end($days)];
        }

        return implode(', ', array_map(fn (int $day): string => $names[$day], $days));
    }

    public function isWorkingDay(CarbonImmutable $day): bool
    {
        $local = $day->setTimezone($this->timezone);

        return in_array($local->dayOfWeekIso, $this->workingDays, true)
            && ! in_array($local->format('Y-m-d'), $this->holidays, true);
    }

    public function isOpen(CarbonImmutable $moment): bool
    {
        $local = $moment->setTimezone($this->timezone);

        if (! $this->isWorkingDay($local)) {
            return false;
        }

        $time = $local->format('H:i');

        return $time >= $this->opensAt && $time < $this->closesAt;
    }

    /**
     * When must the first response happen? Inside hours: now + $minutes (capped at closing time
     * rolls to the next opening). Outside hours: next opening + $graceMinutes.
     */
    public function dueAt(CarbonImmutable $from, int $minutes = 15, int $graceMinutes = 30): CarbonImmutable
    {
        $local = $from->setTimezone($this->timezone);

        if ($this->isOpen($local)) {
            $due = $local->addMinutes($minutes);

            if ($this->isOpen($due)) {
                return $due;
            }

            return $this->nextOpening($due)->addMinutes($minutes);
        }

        return $this->nextOpening($local)->addMinutes($graceMinutes);
    }

    public function nextOpening(CarbonImmutable $from): CarbonImmutable
    {
        $local = $from->setTimezone($this->timezone);
        [$hour, $minute] = array_map('intval', explode(':', $this->opensAt));

        // Long holiday stretches (e.g. Eid) are skipped too, hence the 30-day horizon.
        for ($i = 0; $i <= 30; $i++) {
            $day = $local->startOfDay()->addDays($i);
            $opening = $day->setTime($hour, $minute);

            if ($this->isWorkingDay($day) && $opening->greaterThan($local)) {
                return $opening;
            }
        }

        return $local->addDay();
    }
}
