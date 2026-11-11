<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Marketing;

use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Settings\SalesSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Marketing attribution (slice M): source → enquiry → quoted → won value, read from `leads.source` and the
 * quotations those enquiries produced. A cohort view — enquiries that arrived in the window, followed to
 * whatever they became — so a slow-but-winning channel is not punished for its own sales cycle. Ad spend
 * is deliberately absent: the Meta connection is still awaiting approval, and a cost-per-lead computed
 * from spend we do not have would be an invented number.
 */
#[Layout('layouts::admin')]
#[Title('Attribution')]
class AttributionIndex extends Component
{
    use InteractsWithAdmin;

    #[Url(except: 'quarter')]
    public string $range = 'quarter';

    public function mount(): void
    {
        $this->authorize('social.manage');
    }

    public function render(): View
    {
        [$from, $rangeLabel] = match ($this->range) {
            'month' => [today()->startOfMonth(), 'enquiries that arrived this month'],
            'year' => [today()->startOfYear(), 'enquiries that arrived this year'],
            'all' => [null, 'every enquiry on record'],
            default => [today()->startOfQuarter(), 'enquiries that arrived this quarter'],
        };

        $user = $this->user();

        $cohort = Lead::query()
            ->visibleTo($user)
            ->when($from !== null, fn ($query) => $query->where('leads.created_at', '>=', $from));

        $enquiries = (clone $cohort)
            ->select('leads.source', DB::raw('COUNT(*) as total'))
            ->groupBy('leads.source')
            ->pluck('total', 'source');

        $quoted = (clone $cohort)
            ->whereHas('quotes')
            ->select('leads.source', DB::raw('COUNT(*) as total'))
            ->groupBy('leads.source')
            ->pluck('total', 'source');

        $won = (clone $cohort)
            ->whereHas('quotes', fn ($quotes) => $quotes->where('status', QuoteStatus::Accepted->value))
            ->select('leads.source', DB::raw('COUNT(*) as total'))
            ->groupBy('leads.source')
            ->pluck('total', 'source');

        $wonValue = (clone $cohort)
            ->join('quotes', 'quotes.lead_id', '=', 'leads.id')
            ->where('quotes.status', QuoteStatus::Accepted->value)
            ->select('leads.source', DB::raw('SUM(quotes.total_minor) as total'))
            ->groupBy('leads.source')
            ->pluck('total', 'source');

        $rows = collect(LeadSource::cases())
            ->map(fn (LeadSource $source): array => [
                'source' => $source,
                'enquiries' => (int) ($enquiries[$source->value] ?? 0),
                'quoted' => (int) ($quoted[$source->value] ?? 0),
                'won' => (int) ($won[$source->value] ?? 0),
                'won_minor' => (int) ($wonValue[$source->value] ?? 0),
            ])
            ->filter(fn (array $row): bool => $row['enquiries'] > 0)
            ->sortByDesc('enquiries')
            ->values();

        return view('livewire.admin.marketing.attribution-index', [
            'rows' => $rows,
            'rangeLabel' => $rangeLabel,
            'totals' => [
                'enquiries' => (int) $rows->sum('enquiries'),
                'quoted' => (int) $rows->sum('quoted'),
                'won' => (int) $rows->sum('won'),
                'won_minor' => (int) $rows->sum('won_minor'),
            ],
            'lakh' => app(SalesSettings::class)->lakh_grouping,
        ]);
    }
}
