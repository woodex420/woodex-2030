<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Hubs;

use App\Domain\System\Control\ControlPanel;
use App\Livewire\Admin\Reports\ReportSources;
use App\Livewire\Admin\Reports\RevenueCollections;
use App\Livewire\Admin\Reports\SalesFunnel;

/**
 * P10.1: the Reports page — the old Reports group folded into Overview (owner decision, 17 Oct).
 *
 * Slice D: the control strip above the tabs is the dashboard's six numbers, from the same queries,
 * so the reports and the control view can never disagree. Every report also carries a Today preset
 * for the same period the dashboard measures.
 */
final class ReportsHub extends Hub
{
    protected function control(): array
    {
        $user = auth()->user();

        return $user === null ? [] : app(ControlPanel::class)->cards($user);
    }

    protected function hub(): array
    {
        return [
            'title' => 'Reports',
            'description' => 'How the funnel moves and how the money lands — the two report views side by side.',
            'breadcrumbs' => ['Overview' => null, 'Reports' => null],
            'tabs' => [
                ['id' => 'funnel', 'label' => 'Sales funnel', 'component' => SalesFunnel::class, 'permission' => 'reports.view'],
                ['id' => 'revenue', 'label' => 'Revenue & collections', 'component' => RevenueCollections::class, 'permission' => 'reports.finance'],
                ['id' => 'sources', 'label' => 'Ledger & operations', 'component' => ReportSources::class, 'permission' => 'reports.view'],
            ],
        ];
    }
}
