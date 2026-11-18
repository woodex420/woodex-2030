<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\QuoteEvent;
use App\Domain\Sales\Notifications\QuoteNotification;
use App\Domain\Sales\Services\QuoteLinks;
use App\Domain\System\Alerts\AlertRouter;
use App\Settings\SalesSettings;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * Quotations do not answer themselves (AUT, quotation step 3).
 *
 * Three quiet jobs, run once a day:
 *  1. **Chase** — a quotation sent days ago with no answer: the owner and the salesperson are told to call.
 *  2. **Warn** — a quotation whose price lapses in a few days: told once, before it is too late to save it.
 *  3. **Lapse** — a quotation whose validity has passed: marked expired, logged, and the team told, so a stale
 *     price is never quoted again by accident.
 *
 * The system never writes to the client by itself. It tells the studio, and the studio calls — a person decides
 * what to say to a client who has gone quiet.
 *
 * Each nudge is remembered on the quotation as an event, so the same quotation is not chased every single day:
 * after a chase the clock restarts, and after three the quotation is left alone — a client who has not answered
 * three times has answered.
 */
final class ChaseQuotations
{
    /** How many times one quotation is chased before it is left alone. */
    private const int MAX_CHASES = 3;

    public function __construct(
        private readonly SalesSettings $settings,
        private readonly LogQuoteEvent $log,
    ) {}

    /**
     * @return array{chased: int, expiring: int, expired: int}
     */
    public function handle(): array
    {
        if (! $this->settings->quote_follow_ups_enabled) {
            return ['chased' => 0, 'expiring' => 0, 'expired' => 0];
        }

        return [
            'chased' => $this->chase(),
            'expiring' => $this->warnExpiring(),
            'expired' => $this->lapse(),
        ];
    }

    /** Sent, unanswered, and quiet for long enough: somebody should call. */
    private function chase(): int
    {
        $days = max(1, $this->settings->quote_follow_up_days);
        $sentBefore = now()->subDays($days);

        $quotes = Quote::query()
            ->with('contact:id,name,phone,whatsapp')
            ->whereIn('status', [QuoteStatus::Sent, QuoteStatus::Viewed])
            ->whereNotNull('sent_at')
            ->where('sent_at', '<=', $sentBefore)
            ->get();

        $chased = 0;

        foreach ($quotes as $quote) {
            // How many times this quotation has been chased in all: three unanswered chases are an answer.
            $chases = $this->eventsSince($quote, 'follow_up', $quote->sent_at ?? $sentBefore);

            if ($chases >= self::MAX_CHASES) {
                continue;
            }

            // One chase per period: after the nth chase, the next one is a full period later.
            if ($this->lastEventAt($quote, 'follow_up')?->gt(now()->subDays($days)) === true) {
                continue;
            }

            $this->nudge($quote, 'follow_up', $this->chaseLine($quote));
            $chased++;
        }

        return $chased;
    }

    /** The price lapses in a few days and the client has not accepted: say so while it can still be saved. */
    private function warnExpiring(): int
    {
        $days = max(1, $this->settings->quote_expiry_warning_days);

        $quotes = Quote::query()
            ->with('contact:id,name,phone,whatsapp')
            ->whereIn('status', [QuoteStatus::Sent, QuoteStatus::Viewed])
            ->whereNotNull('valid_until')
            ->whereBetween('valid_until', [today(), today()->addDays($days)])
            ->get();

        $warned = 0;

        foreach ($quotes as $quote) {
            // One warning per quotation: a daily reminder about the same date is noise.
            if ($this->eventsSince($quote, 'expiry_warning', now()->subDays(30)) > 0) {
                continue;
            }

            $this->nudge($quote, 'expiry_warning', $this->expiryLine($quote));
            $warned++;
        }

        return $warned;
    }

    /** Validity passed: the quotation lapses, so nobody quotes a price the studio no longer stands behind. */
    private function lapse(): int
    {
        $quotes = Quote::query()
            ->with('contact:id,name,phone,whatsapp')
            ->whereIn('status', [QuoteStatus::Sent, QuoteStatus::Viewed])
            ->whereNotNull('valid_until')
            ->where('valid_until', '<', today())
            ->get();

        foreach ($quotes as $quote) {
            $quote->update(['status' => QuoteStatus::Expired, 'expired_at' => now()]);

            $this->log->handle(
                $quote,
                'expired',
                null,
                "{$quote->label()} lapsed on {$quote->valid_until?->format('j M Y')} without an answer.",
                ['total_minor' => (int) $quote->total_minor],
            );

            app(AlertRouter::class)->send('quote.chase', QuoteLinks::watchers($quote), new QuoteNotification(
                $quote,
                'expired',
                $this->expiryLine($quote).' Send a fresh quotation if the work is still live.',
            ));
        }

        return $quotes->count();
    }

    private function nudge(Quote $quote, string $type, string $line): void
    {
        $this->log->handle($quote, $type, null, $line, ['total_minor' => (int) $quote->total_minor]);
        app(AlertRouter::class)->send('quote.chase', QuoteLinks::watchers($quote), new QuoteNotification($quote, $type, $line));
    }

    private function chaseLine(Quote $quote): string
    {
        $days = (int) ($quote->sent_at?->diffInDays(now()) ?? 0);
        $seen = $quote->first_viewed_at !== null
            ? 'The client opened it '.((int) $quote->first_viewed_at->diffInDays(now())).' day(s) ago and has not answered.'
            : 'The client has not opened it yet.';

        return "Sent {$days} day(s) ago to {$quote->contact->name}: no answer yet. {$seen} A call today is worth more than another message.";
    }

    private function expiryLine(Quote $quote): string
    {
        $days = max(0, (int) today()->diffInDays($quote->valid_until, false));

        return $quote->valid_until === null
            ? "{$quote->label()} has no validity date."
            : "{$quote->label()} lapses on {$quote->valid_until->format('j M Y')} — {$days} day(s) left, and it is still not accepted.";
    }

    private function eventsSince(Quote $quote, string $type, DateTimeInterface|CarbonInterface $since): int
    {
        return QuoteEvent::query()
            ->where('quote_id', $quote->id)
            ->where('type', $type)
            ->where('occurred_at', '>=', $since)
            ->count();
    }

    private function lastEventAt(Quote $quote, string $type): ?CarbonInterface
    {
        return QuoteEvent::query()
            ->where('quote_id', $quote->id)
            ->where('type', $type)
            ->latest('occurred_at')
            ->value('occurred_at');
    }
}
