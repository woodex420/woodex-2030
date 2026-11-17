<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Exceptions\QuoteActionDenied;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Services\QuoteWriteLock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Starts the next revision of a sent quotation (QUO-013): the quote becomes an editable draft R1, R2…; the sent
 * versions stay frozen and the client keeps seeing the last sent one until the new revision is sent.
 */
final class ReviseQuote
{
    public const array REVISABLE = [QuoteStatus::Sent, QuoteStatus::Viewed, QuoteStatus::ChangesRequested, QuoteStatus::Expired, QuoteStatus::Declined];

    public function __construct(private readonly LogQuoteEvent $log) {}

    public function handle(Quote $quote, User $by): Quote
    {
        $expectedRevision = (int) $quote->revision;

        return DB::transaction(function () use ($quote, $by, $expectedRevision): Quote {
            QuoteWriteLock::refresh($quote);
            if ((int) $quote->revision !== $expectedRevision) {
                throw new QuoteActionDenied('The revision changed. Reload and review the quotation.');
            }
            $by = User::query()->findOrFail($by->id);
            abort_unless($by->is_active, 403);
            Gate::forUser($by)->authorize('revise', $quote);

            if (! in_array($quote->status, self::REVISABLE, true)) {
                throw new QuoteActionDenied("{$quote->label()} is {$quote->status->label()} and cannot be revised.");
            }

            $from = $quote->label();

            if ($quote->versions()->where('revision', $quote->revision)->exists()) {
                $quote->revision = $quote->revision + 1;
            }

            $quote->status = QuoteStatus::Draft;
            $quote->expired_at = null;
            $quote->save();

            $this->log->handle($quote, 'revised', $by, "Revision {$quote->label()} started from {$from}.", ['revision' => (int) $quote->revision]);

            return $quote;
        });
    }
}
