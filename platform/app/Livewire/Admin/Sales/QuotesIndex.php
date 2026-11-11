<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Sales;

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Policies\QuotePolicy;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Settings\SalesSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Quotations list (QUO-016): status chips with counts, search, line/owner filters, value and age, and a
 * "needs follow-up" view for sent quotes without a reply.
 */
#[Layout('layouts::admin')]
#[Title('Quotations')]
class QuotesIndex extends Component
{
    use InteractsWithAdmin, WithPagination;

    /** Days after sending (or the last view) before a quote appears under "Needs follow-up". */
    public const FOLLOW_UP_DAYS = 2;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $line = '';

    #[Url(except: '')]
    public string $owner = '';

    #[Url(except: 'updated')]
    public string $sort = 'updated';

    public function mount(): void
    {
        $this->authorize('viewAny', Quote::class);
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['search', 'status', 'line', 'owner', 'sort'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $user = $this->user();

        $base = Quote::query()
            ->visibleTo($user)
            ->search($this->search)
            ->when($this->line !== '', fn (Builder $q) => $q->where('business_line', $this->line))
            ->when($this->owner !== '', fn (Builder $q) => $q->where('owner_id', (int) $this->owner));

        $counts = (clone $base)->toBase()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $followUp = (clone $base)->tap(fn (Builder $q) => $this->needsFollowUp($q))->count();

        $quotes = (clone $base)
            ->with(['contact:id,name,phone', 'company:id,name', 'owner:id,name', 'lead:id,ref'])
            ->when($this->status === 'follow_up', fn (Builder $q) => $this->needsFollowUp($q))
            ->when($this->status !== '' && $this->status !== 'follow_up', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->sort === 'updated', fn (Builder $q) => $q->latest('updated_at')->latest('id'))
            ->when($this->sort === 'newest', fn (Builder $q) => $q->latest('id'))
            ->when($this->sort === 'value', fn (Builder $q) => $q->orderByDesc('total_minor')->latest('id'))
            ->when($this->sort === 'number', fn (Builder $q) => $q->orderByDesc('number'))
            ->paginate(25);

        return view('livewire.admin.sales.quotes-index', [
            'quotes' => $quotes,
            'counts' => $counts,
            'total' => (int) $counts->sum(),
            'followUp' => $followUp,
            'chips' => [
                QuoteStatus::Draft, QuoteStatus::PendingApproval, QuoteStatus::Sent, QuoteStatus::Viewed,
                QuoteStatus::ChangesRequested, QuoteStatus::Accepted, QuoteStatus::Declined, QuoteStatus::Expired,
            ],
            'lines' => BusinessLine::options(),
            'staff' => $this->staff(),
            'seesAll' => QuotePolicy::seesAll($user),
            'lakh' => app(SalesSettings::class)->lakh_grouping,
        ]);
    }

    /**
     * @param  Builder<Quote>  $query
     */
    private function needsFollowUp(Builder $query): void
    {
        $cutoff = now()->subDays(self::FOLLOW_UP_DAYS);

        $query->whereIn('status', [QuoteStatus::Sent->value, QuoteStatus::Viewed->value])
            ->where(fn (Builder $q) => $q->where('sent_at', '<=', $cutoff))
            ->where(fn (Builder $q) => $q->whereNull('last_viewed_at')->orWhere('last_viewed_at', '<=', $cutoff));
    }
}
