<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Sales;

use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\Quote;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Settings\SalesSettings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Proposals (Gauzy parity, slice K): the quotations that are still a negotiation — drafted, waiting for approval,
 * with the client, or back for changes. "Won" and "lost" live on the quotations screen; this page is the work in
 * progress, with the one number a proposal list is for: how much value is still in play and how long it has waited.
 */
#[Layout('layouts::admin')]
#[Title('Proposals')]
class ProposalsIndex extends Component
{
    use InteractsWithAdmin;
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    /** The statuses a proposal can be in before it is won or lost. */
    public const array OPEN_STATUSES = [
        QuoteStatus::Draft,
        QuoteStatus::PendingApproval,
        QuoteStatus::Approved,
        QuoteStatus::ChangesRequested,
        QuoteStatus::Sent,
        QuoteStatus::Viewed,
    ];

    public function mount(): void
    {
        $this->authorize('quotes.view');
    }

    public function updating(string $name): void
    {
        if ($name === 'search') {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $base = Quote::query()
            ->visibleTo($this->user())
            ->whereIn('status', self::OPEN_STATUSES)
            ->with(['contact:id,name', 'owner:id,name']);

        if (trim($this->search) !== '') {
            $term = '%'.trim($this->search).'%';

            $base->where(fn ($query) => $query
                ->where('number', 'like', $term)
                ->orWhere('title', 'like', $term)
                ->orWhereHas('contact', fn ($contact) => $contact->where('name', 'like', $term)));
        }

        $all = (clone $base)->get(['id', 'status', 'total_minor', 'created_at', 'sent_at']);
        $proposals = (clone $base)->orderByDesc('created_at')->paginate(25);

        $oldestOpenDays = $all
            ->map(fn (Quote $quote): int => (int) $quote->created_at->diffInDays(now()))
            ->max() ?? 0;

        return view('livewire.admin.sales.proposals-index', [
            'proposals' => $proposals,
            'openValue' => (int) $all->sum('total_minor'),
            'awaitingApproval' => $all->where('status', QuoteStatus::PendingApproval)->count(),
            'notSent' => $all->filter(fn (Quote $quote): bool => in_array($quote->status, [QuoteStatus::Draft, QuoteStatus::PendingApproval, QuoteStatus::Approved, QuoteStatus::ChangesRequested], true))->count(),
            'withClient' => $all->filter(fn (Quote $quote): bool => in_array($quote->status, [QuoteStatus::Sent, QuoteStatus::Viewed], true))->count(),
            'oldestOpenDays' => $oldestOpenDays,
            'lakh' => app(SalesSettings::class)->lakh_grouping,
        ]);
    }
}
