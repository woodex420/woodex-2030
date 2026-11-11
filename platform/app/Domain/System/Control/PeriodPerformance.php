<?php

declare(strict_types=1);

namespace App\Domain\System\Control;

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Models\User;
use App\Settings\SalesSettings;
use App\Support\Money;
use App\Support\Reports\ReportRange;
use App\Support\Reports\RevenueCollectionsReport;

/** Selected-period performance reuses the exact financial report queries and definitions. */
final class PeriodPerformance
{
    /** @return list<array{label: string, display: string, hint: string, href: string}> */
    public function cards(User $user, ReportRange $range, ?BusinessLine $line): array
    {
        $cards = [];
        $params = ['preset' => $range->preset, 'from' => $range->from->toDateString(), 'to' => $range->to->toDateString(), 'line' => $line === null ? '' : $line->value];
        if ($user->can('leads.view')) {
            $count = Lead::query()->visibleTo($user)->when($line !== null, fn ($q) => $q->where('business_line', $line?->value))
                ->whereBetween('created_at', [$range->from, $range->to])->count();
            // Deliberately not clickable: the legacy lead list cannot express an arbitrary date range.
            $cards[] = ['label' => 'New enquiries in period', 'display' => (string) $count, 'hint' => 'Your permitted leads · creation date', 'href' => ''];
        }
        if ($user->can('reports.finance') && $user->can('reports.view')) {
            $totals = app(RevenueCollectionsReport::class)->totals($range, $line);
            foreach (['accepted_minor' => 'Accepted quotation value', 'invoiced_minor' => 'Issued invoice value', 'collected_minor' => $line === null ? 'Verified collections' : 'Allocated collections'] as $key => $label) {
                $cards[] = ['label' => $label, 'display' => Money::ofMinor($totals[$key])->format(app(SalesSettings::class)->lakh_grouping),
                    'hint' => $key === 'collected_minor' && $line !== null ? 'Verified allocations to this line; excludes unallocated cash' : 'Period flow · not posted accounting profit',
                    'href' => route('admin.reports.index', ['tab' => 'revenue', ...$params])];
            }
        }

        return $cards;
    }
}
