<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Sales;

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Sales\Actions\DecideQuoteApproval;
use App\Domain\Sales\Exceptions\QuoteActionDenied;
use App\Domain\Sales\Models\Quote;
use App\Domain\System\Control\OperationsSummary;
use App\Domain\System\Enums\ApprovalStatus;
use App\Domain\System\Models\Approval;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Settings\SalesSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Quotations waiting for a discount or margin approval (QUO-007), for owners and sales managers.
 */
#[Layout('layouts::admin')]
#[Title('Approvals')]
class Approvals extends Component
{
    use InteractsWithAdmin, WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $line = '';

    public function updating(string $name): void
    {
        if (in_array($name, ['search', 'line'], true)) {
            $this->resetPage();
        }
    }

    public bool $showReject = false;

    #[Locked]
    public ?int $rejectingId = null;

    public string $rejectReason = '';

    public function approve(int $approvalId): void
    {
        $this->authorize('approve', Quote::class);
        $this->decide($approvalId, true, null);
    }

    public function openReject(int $approvalId): void
    {
        $this->authorize('approve', Quote::class);
        $this->rejectingId = $approvalId;
        $this->rejectReason = '';
        $this->resetValidation();
        $this->showReject = true;
    }

    public function reject(): void
    {
        $this->authorize('approve', Quote::class);
        $this->validate(['rejectReason' => ['required', 'string', 'min:3', 'max:500']], [], ['rejectReason' => 'reason']);

        if ($this->rejectingId !== null && $this->decide($this->rejectingId, false, $this->rejectReason)) {
            $this->showReject = false;
        }
    }

    public function render(SalesSettings $settings): View
    {
        $user = $this->user();
        abort_unless(OperationsSummary::canReview($user), 403);
        abort_unless($this->line === '' || BusinessLine::tryFrom($this->line) !== null, 422, 'Choose a business line.');
        $withQuote = fn (MorphTo $morph) => $morph->morphWith([Quote::class => ['contact:id,name', 'owner:id,name']]);
        $base = Approval::query()->with(['approvable' => $withQuote, 'requester:id,name', 'decider:id,name'])
            ->where('approvable_type', 'quote')
            ->whereHasMorph('approvable', [Quote::class], fn ($q) => $q->search(mb_substr($this->search, 0, 120))->when($this->line !== '', fn ($q) => $q->where('business_line', $this->line)));
        // Other reviewers can use their existing queues without reading quote approval details.
        $pending = $user->can('quotes.approve') ? (clone $base)->where('status', ApprovalStatus::Pending)->oldest()->paginate(25) : null;
        $recent = $user->can('quotes.approve') ? (clone $base)->whereIn('status', [ApprovalStatus::Approved, ApprovalStatus::Rejected])
            ->where('decided_at', '>=', now()->subDays(30))->latest('decided_at')->limit(15)->get() : collect();

        return view('livewire.admin.sales.approvals', [
            'pending' => $pending,
            'recent' => $recent,
            'reviews' => app(OperationsSummary::class)->reviews($user),
            'lakh' => $settings->lakh_grouping,
        ]);
    }

    private function decide(int $approvalId, bool $approve, ?string $reason): bool
    {
        $approval = Approval::query()->where('approvable_type', 'quote')->findOrFail($approvalId);

        try {
            $quote = app(DecideQuoteApproval::class)->handle($approval, $this->user(), $approve, $reason);
        } catch (QuoteActionDenied $exception) {
            $this->toast($exception->getMessage(), 'error');

            return false;
        }

        $this->toast($approve ? "{$quote->label()} approved: the sales person can send it now." : "{$quote->label()} sent back with your reason.");

        return true;
    }
}
