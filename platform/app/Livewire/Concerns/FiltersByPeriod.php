<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;

/**
 * A list cut to the period that a figure of the dashboard counted (master list T-044):
 * `?from=2026-11-01&to=2026-11-20`.
 *
 * Whole days, the first and the last both counted, on the studio's own clock: Asia/Karachi, the clock the
 * dates are kept and printed in. The figure's period and the list's are then the same moments, so a record made
 * one second before midnight is in both or in neither. Either end may be left out.
 *
 * Which date of a record the period is about is the list's own matter (a lead is made, a quotation is sent or
 * accepted, an invoice is issued). A day that is not a day is refused with 422, as the dashboard refuses a
 * period it does not know: a list that quietly ignored it would show rows the figure never counted.
 */
trait FiltersByPeriod
{
    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    /**
     * The first moment of the first day and the last moment of the last; null where that end is open.
     *
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    protected function period(): array
    {
        $from = self::dayOfPeriod($this->from)?->startOfDay();
        $to = self::dayOfPeriod($this->to)?->endOfDay();

        abort_if($from !== null && $to !== null && $from->greaterThan($to), 422, 'The first day of a period cannot be after its last.');

        return [$from, $to];
    }

    protected function hasPeriod(): bool
    {
        return $this->from !== '' || $this->to !== '';
    }

    /** "1 Nov – 20 Nov 2026", "20 Nov 2026" for one day, "from 1 Nov 2026" or "up to 20 Nov 2026" with one end open. */
    protected function periodWords(): string
    {
        [$from, $to] = $this->period();

        return match (true) {
            $from !== null && $to !== null && $from->isSameDay($to) => $from->format('j M Y'),
            $from !== null && $to !== null => $from->format($from->year === $to->year ? 'j M' : 'j M Y').' – '.$to->format('j M Y'),
            $from !== null => 'from '.$from->format('j M Y'),
            $to !== null => 'up to '.$to->format('j M Y'),
            default => '',
        };
    }

    private static function dayOfPeriod(string $value): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }

        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $value) : null;

        // 2026-02-31 is read as 3 March by the date functions; a day that does not read back as itself is no day.
        abort_if($day === null || $day->format('Y-m-d') !== $value, 422, 'Choose a valid date.');

        return $day;
    }
}
