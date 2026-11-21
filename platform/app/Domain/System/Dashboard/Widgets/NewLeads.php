<?php

declare(strict_types=1);

namespace App\Domain\System\Dashboard\Widgets;

use App\Domain\Crm\Models\Lead;
use App\Domain\System\Dashboard\DashboardContext;
use App\Domain\System\Dashboard\Figure;
use App\Domain\System\Dashboard\Widget;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Enquiries by the day they were made, over the last fourteen days, of the leads the person may see. The total
 * under the chart is Lead::createdBetween() over those days, and opens the Leads list cut to them.
 */
final class NewLeads extends Widget
{
    private const DAYS = 14;

    public function key(): string
    {
        return 'new_leads';
    }

    public function title(): string
    {
        return 'New leads, last '.self::DAYS.' days';
    }

    public function visibleTo(User $user): bool
    {
        return $user->can('leads.view');
    }

    public function width(): string
    {
        return 'wide';
    }

    public function data(DashboardContext $context): array
    {
        $now = $context->now;
        $start = $now->subDays(self::DAYS - 1)->startOfDay();
        $end = $now->endOfDay();

        // Grouped in PHP, so that it is the same on MySQL, MariaDB and SQLite.
        $perDay = Lead::query()->visibleTo($context->user)->createdBetween($start, $end)->pluck('created_at')
            ->countBy(fn ($date) => CarbonImmutable::parse($date)->format('Y-m-d'));

        $categories = [];
        $series = [];
        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $categories[] = $day->format('j M');
            $series[] = (int) ($perDay[$day->format('Y-m-d')] ?? 0);
        }

        return [
            'chart' => [
                'type' => 'area',
                'height' => 240,
                'categories' => $categories,
                'series' => [['name' => 'New leads', 'data' => $series]],
            ],
            'total' => new Figure('new_leads.total', 'Enquiries in these '.self::DAYS.' days', array_sum($series),
                route('admin.leads.index', ['from' => $start->toDateString(), 'to' => $end->toDateString()]), noun: ['enquiry', 'enquiries']),
            'dates' => DashboardContext::days($start, $end),
            'own' => ! $context->user->can('leads.view_all'),
        ];
    }

    public function isEmpty(array $data): bool
    {
        return $data['total']->count === 0;
    }

    public function emptyWords(array $data = []): string
    {
        return 'No enquiry was made in these '.self::DAYS.' days'.(isset($data['dates']) ? ' ('.$data['dates'].')' : '').'. New enquiries from the website, WhatsApp and the team are counted here by the day they arrive.';
    }
}
