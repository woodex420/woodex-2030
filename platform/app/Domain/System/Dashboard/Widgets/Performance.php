<?php

declare(strict_types=1);

namespace App\Domain\System\Dashboard\Widgets;

use App\Domain\System\Control\PeriodPerformance;
use App\Domain\System\Dashboard\DashboardContext;
use App\Domain\System\Dashboard\Figure;
use App\Domain\System\Dashboard\Widget;
use App\Models\User;

/**
 * The business in the chosen period: enquiries made, quotations accepted, invoices issued, money collected.
 * The money figures are the revenue report's own totals (PeriodPerformance), so the dashboard and the report
 * cannot say two things.
 */
final class Performance extends Widget
{
    public function key(): string
    {
        return 'performance';
    }

    public function title(): string
    {
        return 'Business performance';
    }

    public function description(): string
    {
        return 'What came in during the period';
    }

    public function visibleTo(User $user): bool
    {
        return $user->can('leads.view') || ($user->can('reports.finance') && $user->can('reports.view'));
    }

    public function followsPeriod(): bool
    {
        return true;
    }

    public function width(): string
    {
        return 'wide';
    }

    /**
     * What each figure counts, in a sentence. The line under the widget says these for the figures that are
     * shown and for no other: a person without the money figures is not told how invoices are counted.
     *
     * @return array<string, string>
     */
    private function meanings(DashboardContext $context): array
    {
        return [
            'enquiries' => 'Enquiries: '.($context->user->can('leads.view_all') ? 'everybody\'s' : 'your own').', by the day they were made',
            'accepted' => 'Accepted: quotations accepted in the period that still stand as accepted',
            'invoiced' => 'Issued: invoices by issue date; drafts and void ones left out; not ledger revenue',
            'collected' => $context->line === null
                ? 'Collected: verified payments by the day received'
                : 'Collected: the verified amounts set against invoices of this line, by the day received',
        ];
    }

    public function data(DashboardContext $context): array
    {
        $figures = [];
        $means = [];
        $meanings = $this->meanings($context);

        foreach (app(PeriodPerformance::class)->cards($context->user, $context->range, $context->line, $context) as $card) {
            $figures[] = [
                'figure' => new Figure(
                    key: 'performance.'.$card['key'],
                    label: $card['label'],
                    count: $card['count'],
                    href: $card['list'],
                    amountMinor: $card['amount_minor'],
                    noun: $card['noun'],
                    hint: $card['hint'],
                ),
                // The matching report, for the people who may open it: the same total, by month and by line.
                'report' => $card['href'] === '' ? null : $card['href'],
            ];
            $means[] = $meanings[$card['key']];
        }

        return [
            'figures' => $figures,
            'means' => $means,
            'money' => array_filter($figures, fn (array $card): bool => $card['figure']->amountMinor !== null) !== [],
            'dates' => $context->periodWords(),
            'line' => $context->line?->label(),
            'lakh' => $context->lakh,
        ];
    }
}
