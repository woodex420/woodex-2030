<?php

declare(strict_types=1);

namespace App\Domain\System\Dashboard;

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\System\Control\ControlPanel;
use App\Models\User;
use App\Support\Reports\ReportRange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What every widget of one drawing of the dashboard is told: who looks, at which period and business line, and
 * when. One ControlPanel is shared, so a number that two widgets show (the six queues and "Needs attention") is
 * asked of the database once; count() does the same for the period's figures.
 */
final class DashboardContext
{
    /**
     * What this drawing has already counted, by the question asked.
     *
     * @var array<string, int>
     */
    private array $counted = [];

    public function __construct(
        public readonly User $user,
        public readonly ReportRange $range,
        public readonly ?BusinessLine $line,
        public readonly CarbonImmutable $now,
        public readonly ControlPanel $panel,
        public readonly bool $lakh = false,
    ) {}

    /**
     * How many records a query finds, asked of the database once in a drawing. Two widgets that show the same
     * number get one reading: the period's enquiries stand under "Business performance" and at the head of the
     * funnel, and the page must not say 8 in one place and 9 in the other because an enquiry arrived between
     * two questions. The question is known by its own SQL, so only a question that is the same is shared.
     *
     * @param  Builder<covariant Model>  $query
     */
    public function count(Builder $query): int
    {
        return $this->counted[$query->toRawSql()] ??= $query->count();
    }

    /**
     * The period as a list's address takes it: whole days, first and last both counted
     * (App\Livewire\Concerns\FiltersByPeriod), with the business line where one is chosen.
     *
     * @param  array<string, string>  $with  the list's own fields, such as ['dated' => 'sent']
     * @return array<string, string>
     */
    public function periodQuery(array $with = []): array
    {
        return [...$with, 'from' => $this->range->from->toDateString(), 'to' => $this->range->to->toDateString(), ...($this->line === null ? [] : ['line' => $this->line->value])];
    }

    /** "1 Nov – 20 Nov 2026", or "20 Nov 2026" for one day: the dates a chart states. */
    public function periodWords(): string
    {
        return self::days($this->range->from, $this->range->to);
    }

    /** Two days as words, the year said once where both are in it. */
    public static function days(CarbonImmutable $from, CarbonImmutable $to): string
    {
        return match (true) {
            $from->isSameDay($to) => $from->format('j M Y'),
            default => $from->format($from->year === $to->year ? 'j M' : 'j M Y').' – '.$to->format('j M Y'),
        };
    }
}
