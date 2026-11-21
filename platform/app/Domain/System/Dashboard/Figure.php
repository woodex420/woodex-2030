<?php

declare(strict_types=1);

namespace App\Domain\System\Dashboard;

use App\Support\Money;

/**
 * One figure of the dashboard, with the records it stands for (master list T-044; the owner's document 05 §7:
 * "Every KPI must link to filtered records").
 *
 * `count` is how many records were counted, and `href` is the list of exactly those records: the list says the
 * same number in its data-list-rows. Both come from one scope of the model (Lead::createdBetween(),
 * Invoice::aged(), …), asked once here to count and once by the list to show, so they cannot drift apart.
 * tests/Feature/Overview/DashboardWidgetsTest.php follows every figure to its list.
 *
 * `rows` is false for the few figures whose link is not such a list. Each of them is named in that test with
 * its reason, so a new figure cannot leave the rule quietly.
 */
final class Figure
{
    /**
     * @param  string  $key  the figure's name on the page, `widget.figure`
     * @param  int  $count  how many records stand behind it
     * @param  string|null  $href  the list of those records; null where the person may not open it
     * @param  bool  $rows  true where `href` opens a list of exactly `count` rows
     * @param  int|null  $amountMinor  the money those records add up to, in paisa; null for a figure that only counts
     * @param  array{0: string, 1: string}  $noun  what one record and several are called: ['invoice', 'invoices']
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly int $count,
        public readonly ?string $href,
        public readonly bool $rows = true,
        public readonly ?int $amountMinor = null,
        public readonly array $noun = ['record', 'records'],
        public readonly string $hint = '',
        public readonly string $tone = 'navy',
        public readonly string $icon = 'activity',
        public readonly ?string $display = null,
    ) {}

    /** What is printed large: the amount where the figure is money, the count where it counts. */
    public function value(bool $lakh = false): string
    {
        return $this->display ?? ($this->amountMinor === null ? number_format($this->count) : Money::ofMinor($this->amountMinor)->format($lakh));
    }

    /** "7 invoices", "1 quotation": the count in words, which is also what the link to the rows says. */
    public function counted(): string
    {
        return number_format($this->count).' '.$this->noun[$this->count === 1 ? 0 : 1];
    }

    /** Whether the figure can be followed to its rows. */
    public function opens(): bool
    {
        return $this->href !== null && $this->rows;
    }
}
