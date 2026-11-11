<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Exceptions\QuoteActionDenied;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Notifications\QuoteNotification;
use App\Domain\Sales\Services\QuoteApprovalRules;
use App\Domain\Sales\Services\QuoteLinks;
use App\Domain\Sales\Services\QuoteWriteLock;
use App\Domain\System\Alerts\AlertRouter;
use App\Domain\System\Enums\ApprovalStatus;
use App\Domain\System\Models\Approval;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Sends a quotation for approval (QUO-007): the quote waits (locked) until an owner or sales manager decides.
 */
final class RequestQuoteApproval
{
    public function __construct(
        private readonly QuoteApprovalRules $rules,
        private readonly LogQuoteEvent $log,
    ) {}

    public function handle(Quote $quote, User $by, ?string $note = null): Approval
    {
        $expectedRevision = (int) $quote->revision;

        return DB::transaction(function () use ($quote, $by, $note, $expectedRevision): Approval {
            QuoteWriteLock::refresh($quote);
            if ((int) $quote->revision !== $expectedRevision) {
                throw new QuoteActionDenied('The revision changed. Reload and review the quotation.');
            }
            $by = User::query()->findOrFail($by->id);
            abort_unless($by->is_active, 403);
            Gate::forUser($by)->authorize('send', $quote);
            if ($quote->status === QuoteStatus::ChangesRequested && $quote->versions()->where('revision', $quote->revision)->exists()) {
                $quote->revision++;
                $quote->save();
            }

            if (! in_array($quote->status, [QuoteStatus::Draft, QuoteStatus::ChangesRequested], true)) {
                throw new QuoteActionDenied("{$quote->label()} is {$quote->status->label()}: only drafts go for approval.");
            }

            $assessment = $this->rules->assess($quote, $by);

            if (! $assessment->needed) {
                throw new QuoteActionDenied('This quotation is within your limits and needs no approval: send it directly.');
            }

            Approval::query()->where('approvable_type', $quote->getMorphClass())->where('approvable_id', $quote->id)
                ->where('status', ApprovalStatus::Pending)->update(['status' => ApprovalStatus::Cancelled, 'decided_at' => now()]);

            $approval = Approval::create([
                'approvable_type' => $quote->getMorphClass(),
                'approvable_id' => $quote->id,
                'type' => 'quote',
                'status' => ApprovalStatus::Pending,
                'requested_by' => $by->id,
                'reason' => filled($note) ? trim((string) $note) : null,
                'payload' => $assessment->toArray() + ['total_minor' => (int) $quote->total_minor, 'revision' => (int) $quote->revision],
                'channel' => 'admin',
            ]);

            $quote->update(['status' => QuoteStatus::PendingApproval]);
            $this->log->handle($quote, 'approval_requested', $by, "Approval requested for {$quote->label()}: ".implode(' ', $assessment->reasons), [
                'discount_bp' => $assessment->discountBp, 'limit_bp' => $assessment->limitBp,
            ]);

            app(AlertRouter::class)->send('quote.approval', QuoteLinks::approvers($by), new QuoteNotification($quote, 'approval_requested',
                'Requested by '.$by->name.': '.implode(' ', $assessment->reasons).(filled($note) ? "\n“".trim((string) $note).'”' : '')));

            return $approval;
        });
    }
}
