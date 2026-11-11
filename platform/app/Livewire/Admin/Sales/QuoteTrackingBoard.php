<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Sales;

use App\Domain\Sales\Actions\LogQuoteEvent;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\QuoteEvent;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Settings\SalesSettings;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * P8.4: the quotation tracking board — grouped lists, event-driven like the auto-chaser
 * (docs/26): no new states, no new columns, the board reads the same truth. A quotation moves
 * because the client acted or the team acted, never because somebody dragged a card.
 */
#[Layout('layouts::admin')]
#[Title('Quote tracking')]
class QuoteTrackingBoard extends Component
{
    use InteractsWithAdmin;

    public string $group = 'follow_up_due';

    public string $chaseNote = '';

    public int $chaseQuoteId = 0;

    public function render(): View
    {
        $days = max(1, (int) app(SalesSettings::class)->quote_follow_up_days);
        $warningDays = max(1, (int) app(SalesSettings::class)->quote_expiry_warning_days);
        $sentBefore = now()->subDays($days);

        $live = Quote::query()
            ->with(['contact:id,name', 'owner:id,name'])
            ->whereIn('status', [QuoteStatus::Sent->value, QuoteStatus::Viewed->value])
            ->whereNotNull('sent_at')
            ->orderBy('sent_at')
            ->limit(120)
            ->get();

        $chaseCounts = QuoteEvent::query()
            ->whereIn('quote_id', $live->pluck('id'))
            ->where('type', 'follow_up')
            ->where('occurred_at', '>=', $live->min('sent_at') ?? now())
            ->selectRaw('quote_id, count(*) as chases, max(occurred_at) as last_chase_at')
            ->groupBy('quote_id')
            ->get()
            ->keyBy('quote_id');

        $groups = [
            'follow_up_due' => ['label' => 'Follow-up due', 'hint' => "Sent more than {$days} days ago, fewer than three chases, last chase older than {$days} days", 'quotes' => collect()],
            'awaiting_reply' => ['label' => 'Awaiting reply', 'hint' => 'Sent recently — the client has had less than '.$days.' days', 'quotes' => collect()],
            'stale' => ['label' => 'Stale', 'hint' => 'Three unanswered chases — an answer in itself; call or close', 'quotes' => collect()],
            'expiring' => ['label' => 'Expiring soon', 'hint' => 'Validity runs out within '.$warningDays.' days', 'quotes' => collect()],
            'won' => ['label' => 'Recent wins', 'hint' => 'Accepted in the last 30 days', 'quotes' => collect()],
            'lost' => ['label' => 'Lost', 'hint' => 'Declined or lapsed in the last 30 days', 'quotes' => collect()],
        ];

        foreach ($live as $quote) {
            /** @var ?object $meta */
            $meta = $chaseCounts->get($quote->id);
            $chases = (int) ($meta->chases ?? 0);
            $lastChase = $meta !== null && $meta->last_chase_at !== null ? Carbon::parse($meta->last_chase_at) : null;

            if ($quote->valid_until !== null && $quote->valid_until->isFuture() && $quote->valid_until->lessThanOrEqualTo(now()->addDays($warningDays))) {
                $groups['expiring']['quotes']->push($quote);
            }

            if ($chases >= 3) {
                $groups['stale']['quotes']->push($quote);
            } elseif ($quote->sent_at->lessThanOrEqualTo($sentBefore)
                && ($lastChase === null || $lastChase->lessThan(now()->subDays($days)))) {
                $groups['follow_up_due']['quotes']->push($quote);
            } else {
                $groups['awaiting_reply']['quotes']->push($quote);
            }
        }

        $groups['won']['quotes'] = Quote::query()
            ->with(['contact:id,name', 'owner:id,name'])
            ->where('status', QuoteStatus::Accepted->value)
            ->where('accepted_at', '>=', now()->subDays(30))
            ->orderByDesc('accepted_at')
            ->limit(10)
            ->get();

        $groups['lost']['quotes'] = Quote::query()
            ->with(['contact:id,name', 'owner:id,name'])
            ->whereIn('status', [QuoteStatus::Declined->value, QuoteStatus::Expired->value])
            ->where('updated_at', '>=', now()->subDays(30))
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();

        foreach ($groups as $key => $group) {
            $groups[$key]['count'] = $group['quotes']->count();
            $groups[$key]['value_minor'] = $group['quotes']->sum('total_minor');
        }

        return view('livewire.admin.sales.quote-tracking-board', [
            'groups' => $groups,
            'active' => $groups[$this->group]['quotes'] ?? collect(),
            'canManage' => $this->user()->can('quotes.manage'),
        ]);
    }

    public function showGroup(string $group): void
    {
        $this->group = $group;
    }

    public function startChase(int $quoteId): void
    {
        abort_unless($this->user()->can('quotes.manage'), 403);

        $this->chaseQuoteId = $quoteId;
        $this->chaseNote = '';
    }

    public function logChase(): void
    {
        abort_unless($this->user()->can('quotes.manage'), 403);

        $validated = $this->validate([
            'chaseNote' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $quote = Quote::query()->findOrFail($this->chaseQuoteId);

        app(LogQuoteEvent::class)->handle(
            $quote,
            'follow_up',
            $this->user(),
            'Follow-up: '.trim($validated['chaseNote']),
            ['total_minor' => (int) $quote->total_minor],
        );

        $this->chaseQuoteId = 0;
        $this->chaseNote = '';

        $this->toast('Chase logged on '.$quote->label().'.', 'success');
    }

    public function money(int $minor): string
    {
        return Money::ofMinor($minor)->format();
    }
}
