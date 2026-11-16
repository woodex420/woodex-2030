<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Exceptions\QuoteActionDenied;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Notifications\QuoteNotification;
use App\Domain\Sales\Services\QuoteApprovalRules;
use App\Domain\System\Alerts\AlertRouter;
use App\Domain\System\Enums\ApprovalStatus;
use App\Domain\System\Models\Approval;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Approve or decline a pending quotation approval (QUO-007). An approval covers the exact figures it was asked
 * for; nobody approves their own request except the owner.
 */
final class DecideQuoteApproval
{
    public function __construct(
        private readonly QuoteApprovalRules $rules,
        private readonly LogQuoteEvent $log,
    ) {}

    public function handle(Approval $approval, User $by, bool $approve, ?string $reason = null): Quote
    {
        $context = Approval::query()->findOrFail($approval->id);
        if ($context->approvable_type !== (new Quote)->getMorphClass()) {
            throw new QuoteActionDenied('This is not a quotation approval.');
        }

        return DB::transaction(function () use ($context, $approval, $by, $approve, $reason): Quote {
            $quote = Quote::query()->whereKey($context->approvable_id)->lockForUpdate()->firstOrFail();
            $locked = Approval::query()->whereKey($approval->id)->lockForUpdate()->firstOrFail();
            if ($locked->approvable_type !== $context->approvable_type || $locked->approvable_id !== $quote->id) {
                throw new QuoteActionDenied('The approval changed. Reload and review the quotation.');
            }
            $approval->setRawAttributes($locked->getAttributes(), true);
            $approval->unsetRelations();
            $by = User::query()->findOrFail($by->id);
            abort_unless($by->is_active, 403);

            if (! $quote instanceof Quote || $approval->status !== ApprovalStatus::Pending || $quote->status !== QuoteStatus::PendingApproval) {
                throw new QuoteActionDenied('This approval was already decided or withdrawn.');
            }

            if (! $by->can('quotes.approve')) {
                throw new QuoteActionDenied('Only the owner or a sales manager can decide approvals.');
            }

            if ($approval->requested_by === $by->id && ! $by->hasRole('owner')) {
                throw new QuoteActionDenied('You cannot approve your own request: ask the owner or another manager.');
            }

            if (! $approve && blank($reason)) {
                throw new QuoteActionDenied('Please say why, so the sales person knows what to change.');
            }

            if ($approve) {
                $requested = $approval->payload['fingerprint'] ?? null;
                $current = $this->rules->assess($quote, $by)->fingerprint;
                if (! is_string($requested) || ! hash_equals($requested, $current)) {
                    throw new QuoteActionDenied('The requested quotation changed. Withdraw it and request approval again.');
                }
            }

            $approval->update([
                'status' => $approve ? ApprovalStatus::Approved : ApprovalStatus::Rejected,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'reason' => $approve ? $approval->reason : trim((string) $reason),
            ]);

            $meta = $quote->meta ?? [];
            unset($meta['approval']);

            if ($approve) {
                $fingerprint = $this->rules->assess($quote, $by)->fingerprint;
                $meta['approval'] = ['fingerprint' => $fingerprint, 'approval_id' => $approval->id, 'by' => $by->id];
                $quote->update(['status' => QuoteStatus::Approved, 'approved_by' => $by->id, 'approved_at' => now(), 'meta' => $meta]);
                $this->log->handle($quote, 'approved', $by, "{$quote->label()} approved by {$by->name}.");
            } else {
                $quote->update(['status' => QuoteStatus::Draft, 'approved_by' => null, 'approved_at' => null, 'meta' => $meta]);
                $this->log->handle($quote, 'approval_rejected', $by, "Approval for {$quote->label()} declined by {$by->name}: ".trim((string) $reason));
            }

            $approval->loadMissing('requester.telegramLink');
            app(AlertRouter::class)->send('quote.approval', [$approval->requester], new QuoteNotification($quote->refresh(), $approve ? 'approved' : 'rejected',
                ($approve ? 'Approved by ' : 'Declined by ').$by->name.($approve ? ': you can send it now.' : ': '.trim((string) $reason))));

            return $quote;
        });
    }
}
