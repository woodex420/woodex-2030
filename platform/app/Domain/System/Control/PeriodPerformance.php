<?php

declare(strict_types=1);

namespace App\Domain\System\Control;

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Policies\QuotePolicy;
use App\Domain\System\Dashboard\DashboardContext;
use App\Models\User;
use App\Settings\SalesSettings;
use App\Support\Money;
use App\Support\Reports\ReportRange;
use App\Support\Reports\RevenueCollectionsReport;
use Illuminate\Database\Eloquent\Builder;

/**
 * Selected-period performance reuses the exact financial report queries and definitions.
 *
 * Each figure also says how many records it adds up (`count`) and where exactly those records are listed
 * (`list`): the count and the list ask one scope of the model, the scope the report's total asks too (master
 * list T-044). `href` stays the matching report. `list` is null where the person may not open that list, or
 * would see only a part of it: the figure is then shown without a door, not with one that opens other rows.
 */
final class PeriodPerformance
{
    /** @return list<array{key: string, label: string, display: string, hint: string, href: string, count: int, list: string|null, noun: array{0: string, 1: string}, amount_minor: int|null}> */
    public function cards(User $user, ReportRange $range, ?BusinessLine $line, ?DashboardContext $context = null): array
    {
        $cards = [];
        // On the dashboard a count is asked through the drawing, which asks the same question once for every
        // widget that shows it (DashboardContext::count).
        $counted = fn (Builder $records): int => $context?->count($records) ?? $records->count();
        $params = ['preset' => $range->preset, 'from' => $range->from->toDateString(), 'to' => $range->to->toDateString(), 'line' => $line === null ? '' : $line->value];
        // The period as a list's address takes it (App\Livewire\Concerns\FiltersByPeriod): whole days.
        $period = ['from' => $range->from->toDateString(), 'to' => $range->to->toDateString(), ...($line === null ? [] : ['line' => $line->value])];
        $inLine = fn (Builder $q) => $q->when($line !== null, fn (Builder $inner) => $inner->where('business_line', $line?->value));

        if ($user->can('leads.view')) {
            $count = $counted(Lead::query()->visibleTo($user)->tap($inLine)->createdBetween($range->from, $range->to));
            $cards[] = [
                'key' => 'enquiries', 'label' => 'New enquiries in period', 'display' => (string) $count,
                'hint' => 'Your permitted leads · creation date', 'href' => '',
                'count' => $count, 'list' => route('admin.leads.index', $period), 'noun' => ['enquiry', 'enquiries'], 'amount_minor' => null,
            ];
        }

        if ($user->can('reports.finance') && $user->can('reports.view')) {
            $totals = app(RevenueCollectionsReport::class)->totals($range, $line);
            $report = route('admin.reports.index', ['tab' => 'revenue', ...$params]);
            $lakh = app(SalesSettings::class)->lakh_grouping;
            $flow = 'Period flow · not posted accounting profit';

            // The figures are the studio's. The quotations list shows a person their own unless they see all,
            // so only somebody who sees all is given the door to it.
            $figures = [
                ['accepted', 'accepted_minor', 'Accepted quotation value', $flow, ['quotation', 'quotations'],
                    $counted(Quote::query()->tap($inLine)->acceptedBetween($range->from, $range->to)),
                    $user->can('quotes.view') && QuotePolicy::seesAll($user) ? route('admin.quotes.index', ['dated' => 'accepted', ...$period]) : null],
                ['invoiced', 'invoiced_minor', 'Issued invoice value', $flow, ['invoice', 'invoices'],
                    $counted(Invoice::query()->tap($inLine)->issuedBetween($range->from, $range->to)),
                    $user->can('invoices.view') ? route('admin.invoices.index', ['dated' => 'issued', ...$period]) : null],
                ['collected', 'collected_minor', $line === null ? 'Verified collections' : 'Allocated collections',
                    $line === null ? $flow : 'Verified allocations to this line; excludes unallocated cash', ['payment', 'payments'],
                    $counted(Payment::query()->collectedBetween($range->from, $range->to, $line)),
                    $user->can('invoices.view') ? route('admin.payments.index', ['dated' => 'received', ...$period]) : null],
            ];

            foreach ($figures as [$key, $total, $label, $hint, $noun, $count, $list]) {
                $cards[] = [
                    'key' => $key, 'label' => $label, 'display' => Money::ofMinor($totals[$total])->format($lakh),
                    'hint' => $hint, 'href' => $report,
                    'count' => $count, 'list' => $list, 'noun' => $noun, 'amount_minor' => $totals[$total],
                ];
            }
        }

        return $cards;
    }
}
