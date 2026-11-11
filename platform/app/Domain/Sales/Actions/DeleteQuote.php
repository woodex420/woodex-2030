<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Exceptions\QuoteActionDenied;
use App\Domain\Sales\Exceptions\QuoteLocked;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Services\QuoteWriteLock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Moves an unsent draft to the recycle bin (GLB-010). Sent quotes are never deleted: they are declined, expire or
 * are replaced by a revision. The number is not reused.
 */
final class DeleteQuote
{
    public function __construct(private readonly LogQuoteEvent $logQuoteEvent) {}

    public function handle(Quote $quote, User $by): void
    {
        $expectedRevision = (int) $quote->revision;
        DB::transaction(function () use ($quote, $by, $expectedRevision): void {
            QuoteWriteLock::refresh($quote);
            if ((int) $quote->revision !== $expectedRevision) {
                throw new QuoteActionDenied('The revision changed. Reload and review the quotation.');
            }
            $by = User::query()->findOrFail($by->id);
            abort_unless($by->is_active, 403);
            Gate::forUser($by)->authorize('delete', $quote);

            if ($quote->sent_at !== null || ! $quote->isEditable()) {
                throw QuoteLocked::for($quote);
            }

            $this->logQuoteEvent->handle($quote, 'deleted', $by, "Draft quotation {$quote->label()} moved to the recycle bin.");
            $quote->delete();

        });
    }
}
