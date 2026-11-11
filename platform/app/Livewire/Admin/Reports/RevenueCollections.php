<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Reports;

use App\Domain\Crm\Enums\BusinessLine;
use App\Settings\SalesSettings;
use App\Support\Reports\Csv;
use App\Support\Reports\ReportRange;
use App\Support\Reports\RevenueCollectionsReport;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Revenue & Collections (RPT-005): quoted → accepted → invoiced → collected per month, by business line,
 * by method and bank — with the previous period for comparison and a CSV for the accountant's records.
 */
#[Layout('layouts::admin')]
#[Title('Revenue & collections')]
class RevenueCollections extends Component
{
    /** In the URL, so both reports can be linked on any period, including today. */
    #[Url(except: 'this_quarter')]
    public string $preset = 'this_quarter';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    #[Url]
    public string $line = '';

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
        $this->authorize('reports.finance');
        abort_unless($this->line === '' || BusinessLine::tryFrom($this->line) !== null, 422, 'Choose a business line.');

        return app(RevenueCollectionsReport::class)->handle($this->range(), BusinessLine::tryFrom($this->line));
    }

    public function export(): StreamedResponse
    {
        $this->authorize('reports.finance');

        $report = $this->report();
        $range = $this->range();

        $rows = [
            ['Revenue & collections', $range->label()],
            ['Business line', BusinessLine::tryFrom($this->line)?->label() ?? 'All'],
            ['Current dates', $range->from->format('Y-m-d').' to '.$range->to->format('Y-m-d')],
            ['Previous dates', $range->previous()->from->format('Y-m-d').' to '.$range->previous()->to->format('Y-m-d')],
            ['Basis', $this->line === '' ? 'Verified receipts, including unallocated cash' : 'Verified allocations to this business line'],
            ['Metric', 'This period', 'Previous period'],
            ['Quoted (Rs)', round($report['totals']['quoted_minor'] / 100, 2), round($report['previous']['quoted_minor'] / 100, 2)],
            ['Accepted (Rs)', round($report['totals']['accepted_minor'] / 100, 2), round($report['previous']['accepted_minor'] / 100, 2)],
            ['Invoiced (Rs)', round($report['totals']['invoiced_minor'] / 100, 2), round($report['previous']['invoiced_minor'] / 100, 2)],
            ['Collected (Rs)', round($report['totals']['collected_minor'] / 100, 2), round($report['previous']['collected_minor'] / 100, 2)],
            ['Outstanding today (Rs)', round($report['totals']['outstanding_minor'] / 100, 2)],
            [],
            ['Month', 'Quoted (Rs)', 'Accepted (Rs)', 'Invoiced (Rs)', 'Collected (Rs)'],
        ];

        foreach ($report['months'] as $row) {
            $rows[] = [$row['month'], round($row['quoted_minor'] / 100, 2), round($row['accepted_minor'] / 100, 2), round($row['invoiced_minor'] / 100, 2), round($row['collected_minor'] / 100, 2)];
        }

        $rows[] = [];
        $rows[] = ['Business line', 'Accepted (Rs)', 'Invoiced (Rs)', 'Collected (Rs)'];

        foreach ($report['by_line'] as $row) {
            $rows[] = [$row['label'], round($row['accepted_minor'] / 100, 2), round($row['invoiced_minor'] / 100, 2), round($row['collected_minor'] / 100, 2)];
        }

        $rows[] = [];
        $rows[] = ['Method', 'Payments', 'Collected (Rs)'];

        foreach ($report['by_method'] as $row) {
            $rows[] = [$row['label'], $row['count'], round($row['amount_minor'] / 100, 2)];
        }

        return Csv::download('revenue-collections-'.now()->format('Y-m-d').'.csv', ['Revenue and collections report'], $rows);
    }

    public function render(SalesSettings $settings): View
    {
        $this->authorize('reports.finance');

        return view('livewire.admin.reports.revenue-collections', [
            'lakh' => $settings->lakh_grouping,
        ]);
    }
}
