<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Reports;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** A permission-aware directory of the real report sources, not a duplicate reporting engine. */
#[Layout('layouts::admin')]
final class ReportSources extends Component
{
    public function render(): View
    {
        $this->authorize('reports.view');
        $definitions = [
            ['accounts.view', 'Trial balance', 'admin.accounts.trial', 'Posted journal debits and credits; reconcile before interpreting profit.'],
            ['accounts.view', 'Profit & loss', 'admin.accounts.profit', 'Posted income and expenses. Not invoice gross value or customer deposits.'],
            ['accounts.view', 'Payables aging', 'admin.accounts.payables', 'Vendor bills and settlements in their native payable ledger.'],
            ['invoices.view', 'Receivables', 'admin.payments.index', 'Current client balances and aging; not a reconstructed historical balance.'],
            ['projects.view', 'Project delivery', 'admin.projects.board', 'Actual stages, dates and stored progress; not a guessed profitability report.'],
            ['social.manage', 'Marketing attribution', 'admin.marketing.index', 'Attributed sources and conversions; no invented ad spend or ROI.'],
            ['automation.manage', 'Automation runs', 'admin.automation.index', 'Recorded execution outcomes, not proof that an external client received a message.'],
            ['system.health', 'System health', 'admin.system.platform', 'Database, scheduler, queue and backup checks from the native collector.'],
        ];
        $sources = [];
        foreach ($definitions as [$permission, $title, $route, $definition]) {
            if (auth()->user()->can($permission)) {
                $params = match ($route) {
                    'admin.payments.index' => ['tab' => 'receivables'],
                    'admin.marketing.index' => ['tab' => 'attribution'],
                    default => [],
                };
                $sources[] = ['title' => $title, 'definition' => $definition, 'href' => route($route, $params)];
            }
        }

        return view('livewire.admin.reports.report-sources', ['sources' => $sources]);
    }
}
