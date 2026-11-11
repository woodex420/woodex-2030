<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Reports;

use App\Settings\SalesSettings;
use App\Support\Reports\Csv;
use App\Support\Reports\ReportRange;
use App\Support\Reports\SalesFunnelReport;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sales Funnel (RPT-002): enquiries in, quotations out, wins, losses and their reasons — by source, line
 * and owner, for any period, with the previous period for comparison and a CSV for the owner's records.
 */
#[Layout('layouts::admin')]
#[Title('Sales funnel')]
class SalesFunnel extends Component
{
    /** In the URL, so a link from the control view opens the report already on the same period. */
    #[Url(except: 'this_month')]
    public string $preset = 'this_month';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    public function setPreset(string $preset): void
    {
        if (in_array($preset, ReportRange::PRESETS, true)) {
            $this->preset = $preset;
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    #[Computed]
    public function range(): ReportRange
    {
        abort_unless(in_array($this->preset, ReportRange::PRESETS, true), 422, 'Choose a report period.');
        if ($this->preset === 'custom') {
            $this->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        }

        return ReportRange::fromPreset($this->preset, $this->from, $this->to);
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function report(): array
    {
        $this->authorize('reports.view');

        return app(SalesFunnelReport::class)->handle($this->range());
    }

    public function export(): StreamedResponse
    {
        $this->authorize('reports.view');

        $report = $this->report();
        $range = $this->range();

        $rows = [
            ['Sales funnel', $range->label()],
            ['Metric', 'This period', 'Previous period'],
            ['Leads', $report['headline']['leads'], $report['previous']['leads']],
            ['Quotes sent', $report['headline']['quotes_sent'], $report['previous']['quotes_sent']],
            ['Accepted', $report['headline']['accepted'], $report['previous']['accepted']],
            ['Accepted value (Rs)', round($report['headline']['accepted_value_minor'] / 100, 2), round($report['previous']['accepted_value_minor'] / 100, 2)],
            ['Won', $report['headline']['won'], $report['previous']['won']],
            ['Lost', $report['headline']['lost'], $report['previous']['lost']],
            ['Win rate %', $report['headline']['win_rate'], $report['previous']['win_rate']],
            [],
            ['By source', 'Leads', 'Quotes sent', 'Accepted', 'Accepted value (Rs)', 'Win rate %'],
        ];

        foreach ($report['by_source'] as $row) {
            $rows[] = [$row['label'], $row['leads'], $row['quotes_sent'], $row['accepted'], round($row['accepted_value_minor'] / 100, 2), $row['win_rate']];
        }

        $rows[] = [];
        $rows[] = ['By business line', 'Leads', 'Quotes sent', 'Accepted', 'Accepted value (Rs)', 'Win rate %'];

        foreach ($report['by_line'] as $row) {
            $rows[] = [$row['label'], $row['leads'], $row['quotes_sent'], $row['accepted'], round($row['accepted_value_minor'] / 100, 2), $row['win_rate']];
        }

        $rows[] = [];
        $rows[] = ['By owner', 'Leads', 'Quotes sent', 'Accepted', 'Accepted value (Rs)', 'Win rate %'];

        foreach ($report['by_owner'] as $row) {
            $rows[] = [$row['label'], $row['leads'], $row['quotes_sent'], $row['accepted'], round($row['accepted_value_minor'] / 100, 2), $row['win_rate']];
        }

        $rows[] = [];
        $rows[] = ['Lost reasons', 'Count'];

        foreach ($report['drop_offs'] as $row) {
            $rows[] = [$row['reason'], $row['count']];
        }

        return Csv::download('sales-funnel-'.now()->format('Y-m-d').'.csv', ['Sales funnel report'], $rows);
    }

    public function render(SalesSettings $settings): View
    {
        $this->authorize('reports.view');

        return view('livewire.admin.reports.sales-funnel', [
            'lakh' => $settings->lakh_grouping,
        ]);
    }
}
