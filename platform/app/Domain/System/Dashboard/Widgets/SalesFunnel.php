<?php

declare(strict_types=1);

namespace App\Domain\System\Dashboard\Widgets;

use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Policies\QuotePolicy;
use App\Domain\System\Dashboard\DashboardContext;
use App\Domain\System\Dashboard\Figure;
use App\Domain\System\Dashboard\Widget;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * The sales funnel of the period, with its conversion rate (the owner's document 05 §7).
 *
 * Three steps, each a count of what happened in the period: enquiries made, quotations sent, quotations
 * accepted. They are the scopes the sales funnel report asks (Lead::createdBetween, Quote::sentBetween,
 * Quote::acceptedBetween), here cut to the records the person may see, because each figure opens the person's
 * own list. For somebody who sees everything the three counts are the report's.
 *
 * The owner's document also names "qualified leads" and "estimates". The pipeline has no stage that means
 * qualified, and an estimate belongs to an optional module, so neither is a step here (DL-044).
 *
 * The rate is quotations accepted ÷ quotations sent, both in the period: the report's "win rate". It is a rate
 * of two flows, not of one group followed through: a quotation sent last month and accepted this month counts
 * as accepted here and as sent there. The widget says so.
 */
final class SalesFunnel extends Widget
{
    public function key(): string
    {
        return 'sales_funnel';
    }

    public function title(): string
    {
        return 'Sales funnel';
    }

    public function description(): string
    {
        return 'From enquiry to accepted quotation';
    }

    public function visibleTo(User $user): bool
    {
        return $user->can('leads.view') && $user->can('quotes.view');
    }

    public function followsPeriod(): bool
    {
        return true;
    }

    public function width(): string
    {
        return 'narrow';
    }

    public function data(DashboardContext $context): array
    {
        $user = $context->user;
        [$from, $to, $line] = [$context->range->from, $context->range->to, $context->line];
        $inLine = fn (Builder $q) => $q->when($line !== null, fn (Builder $inner) => $inner->where('business_line', $line?->value));

        // Asked through the context: the enquiries and the accepted quotations also stand under "Business
        // performance", and the two widgets are given one reading (DashboardContext::count).
        $enquiries = $context->count(Lead::query()->visibleTo($user)->tap($inLine)->createdBetween($from, $to));
        $sent = $context->count(Quote::query()->visibleTo($user)->tap($inLine)->sentBetween($from, $to));
        $acceptedQuery = Quote::query()->visibleTo($user)->tap($inLine)->acceptedBetween($from, $to);
        $accepted = $context->count(clone $acceptedQuery);
        $acceptedValue = (int) (clone $acceptedQuery)->sum('total_minor');

        return [
            'steps' => [
                new Figure('sales_funnel.enquiries', 'Enquiries made', $enquiries, route('admin.leads.index', $context->periodQuery()), noun: ['enquiry', 'enquiries']),
                new Figure('sales_funnel.sent', 'Quotations sent', $sent, route('admin.quotes.index', $context->periodQuery(['dated' => 'sent'])), noun: ['quotation', 'quotations']),
                new Figure('sales_funnel.accepted', 'Quotations accepted', $accepted, route('admin.quotes.index', $context->periodQuery(['dated' => 'accepted'])), noun: ['quotation', 'quotations']),
            ],
            'most' => max(1, $enquiries, $sent, $accepted),
            // Null while nothing was sent: a rate over nothing is not 0 %, it is not a rate.
            'rate' => $sent > 0 ? (int) round($accepted * 100 / $sent) : null,
            'accepted_value' => Money::ofMinor($acceptedValue)->format($context->lakh),
            'dates' => $context->periodWords(),
            'line' => $line?->label(),
            // Whose records: everybody's, or the person's own.
            'own' => ! ($user->can('leads.view_all') && QuotePolicy::seesAll($user)),
        ];
    }

    public function isEmpty(array $data): bool
    {
        return array_sum(array_map(fn (Figure $step): int => $step->count, $data['steps'])) === 0;
    }

    public function emptyWords(array $data = []): string
    {
        return 'No enquiry was made and no quotation was sent or accepted in this period'.(isset($data['dates']) ? ' ('.$data['dates'].')' : '').'.';
    }
}
