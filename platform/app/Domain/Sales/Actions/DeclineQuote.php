<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Exceptions\QuoteActionDenied;
use App\Domain\Sales\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The client said no, and a member of staff records it with the reason given.
 *
 * Until this action existed nothing in the code could set a quotation to Declined: the status was read by the
 * tracking board and by "Revise", and never written. The quotation stays what was sent — its frozen versions are
 * untouched — and can be revised and sent again. The lead is left where it is: one declined offer is not a lost
 * client, and marking a lead lost is a decision of its own, with its own reason, in the CRM.
 *
 * Nobody is alerted. The person who records the answer is the one who heard it; everyone else finds it on the
 * client's timeline and in the "Lost" group of the tracking board.
 */
final class DeclineQuote
{
    public function __construct(private readonly LogQuoteEvent $log) {}

    public function handle(Quote $quote, User $by, string $reason, ?int $revision = null): Quote
    {
        $expectedRevision = $revision ?? (int) $quote->revision;
        $original = $quote;
        $reason = trim($reason);

        DB::transaction(function () use ($quote, $by, $reason, $expectedRevision): void {
            $quote = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            $by = User::query()->findOrFail($by->id);
            abort_unless($by->is_active, 403);
            Gate::forUser($by)->authorize('accept', $quote);

            if ((int) $quote->revision !== $expectedRevision) {
                throw new QuoteActionDenied('This quotation has changed. Reload it and review the current revision before recording an answer.');
            }

            if (! in_array($quote->status, [QuoteStatus::Sent, QuoteStatus::Viewed, QuoteStatus::ChangesRequested], true)) {
                throw new QuoteActionDenied("{$quote->label()} is {$quote->status->label()}: only a quotation that is with the client can be marked as declined.");
            }

            if ($reason === '') {
                throw new QuoteActionDenied('Give the reason the client gave, in a few words.');
            }

            $quote->update([
                'status' => QuoteStatus::Declined,
                'declined_at' => now(),
                // A reply that sounded like a yes is answered by this too: the banner must stop asking.
                'acceptance_hint_at' => null,
                'acceptance_hint_message_id' => null,
            ]);

            $this->log->handle(
                $quote,
                'declined',
                $by,
                "{$by->name} recorded that {$quote->contact->name} declined {$quote->label()}: “{$reason}”",
                ['reason' => $reason, 'total_minor' => (int) $quote->total_minor, 'recorded_by_staff' => true],
                ['channel' => 'staff'],
            );
        });

        return $original->refresh();
    }
}
