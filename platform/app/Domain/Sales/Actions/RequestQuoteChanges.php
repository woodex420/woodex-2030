<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Exceptions\QuoteActionDenied;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Notifications\QuoteNotification;
use App\Domain\Sales\Services\QuoteLinks;
use App\Domain\Sales\Services\QuoteWriteLock;
use App\Domain\System\Alerts\AlertRouter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The client asks for changes on the quotation page (QUO-009): the quote goes back to the team (editable) with the
 * comment on the timeline and an alert.
 */
final class RequestQuoteChanges
{
    public function __construct(private readonly LogQuoteEvent $log) {}

    public function handle(Quote $quote, string $comment, ?string $ip, ?string $userAgent, ?int $revision = null): Quote
    {
        $expectedRevision = $revision ?? (int) $quote->revision;

        return DB::transaction(function () use ($quote, $comment, $ip, $userAgent, $expectedRevision): Quote {
            QuoteWriteLock::refresh($quote);
            if ((int) $quote->revision !== $expectedRevision) {
                throw new QuoteActionDenied('The revision changed. Reload and review the quotation.');
            }

            if (! in_array($quote->status, [QuoteStatus::Sent, QuoteStatus::Viewed], true)) {
                throw new QuoteActionDenied('This quotation is not open for comments right now. Please contact us.');
            }

            $comment = trim($comment);
            $quote->update(['status' => QuoteStatus::ChangesRequested]);
            $this->log->handle($quote, 'changes_requested', null, "{$quote->contact->name} asked for changes to {$quote->label()}: “{$comment}”", [
                'comment' => $comment,
            ], ['channel' => 'link', 'ip' => $ip, 'user_agent' => mb_substr((string) $userAgent, 0, 250)]);

            app(AlertRouter::class)->send('quote.changes_requested', QuoteLinks::watchers($quote), new QuoteNotification($quote, 'changes_requested', '“'.Str::limit($comment, 300).'”'));

            return $quote;
        });
    }
}
