<?php

declare(strict_types=1);

namespace App\Support\Reports;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The period every report screen and CSV agrees on (RPT-009): a preset (or an explicit custom range), plus
 * the equal-length period before it, so a screen can always say "better or worse than last period" without
 * three screens each inventing their own math.
 */
final class ReportRange
{
    public const PRESETS = ['today', 'this_month', 'last_month', 'last_90_days', 'this_quarter', 'this_year', 'custom'];

    public function __construct(
        public readonly string $preset,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {}

    public static function fromPreset(string $preset, ?string $from = null, ?string $to = null): self
    {
        $now = CarbonImmutable::now();

        [$from, $to] = match ($preset) {
            // The control view's period (deep QA slice D): the same day the dashboard's six numbers count.
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'this_month' => [$now->startOfMonth(), $now->endOfDay()],
            'last_month' => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
            'last_90_days' => [$now->subDays(89)->startOfDay(), $now->endOfDay()],
            'this_quarter' => [$now->startOfQuarter(), $now->endOfDay()],
            'this_year' => [$now->startOfYear(), $now->endOfDay()],
            'custom' => [
                CarbonImmutable::parse((string) $from)->startOfDay(),
                CarbonImmutable::parse((string) $to)->endOfDay(),
            ],
            default => throw new InvalidArgumentException('Unknown report period.'),
        };

        if ($from->greaterThan($to)) {
            throw new InvalidArgumentException('The start of a period cannot be after its end.');
        }

        return new self($preset, $from, $to);
    }

    public function label(): string
    {
        return match ($this->preset) {
            'today' => 'Today',
            'this_month' => 'This month',
            'last_month' => 'Last month',
            'last_90_days' => 'Last 90 days',
            'this_quarter' => 'This quarter',
            'this_year' => 'This year ('.now()->format('Y').')',
            'custom' => $this->from->format('j M Y').' – '.$this->to->format('j M Y'),
            default => $this->preset,
        };
    }

    /**
     * The equal-length period immediately before this one: last month vs the month before it, the previous
     * quarter, the 90 days before these 90. The comparison every screen shows first.
     */
    public function previous(): self
    {
        $length = $this->from->diff($this->to)->totalSeconds;

        $previousFrom = match ($this->preset) {
            'this_month' => $this->from->subMonthNoOverflow()->startOfMonth(),
            'last_month' => $this->from->subMonthNoOverflow()->startOfMonth(),
            'this_quarter' => $this->from->subQuarterNoOverflow()->startOfQuarter(),
            'this_year' => $this->from->subYear()->startOfYear(),
            default => $this->from->subSeconds((int) $length + 1)->startOfDay(),
        };

        $previousTo = $this->from->subSecond();

        return new self($this->preset, $previousFrom, $previousTo);
    }
}
