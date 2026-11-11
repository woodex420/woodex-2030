<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services;

use App\Domain\Sales\Models\Quote;
use App\Models\User;
use App\Support\Money;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/**
 * The client's quotation link (QUO-009) and who hears about a quote (QUO-010).
 *
 * The link is a relative signed URL: it works on whichever domain serves the app (interior.woodex.com.pk today,
 * the main domain later) and expires 60 days after the validity date. It always shows the latest sent revision.
 */
final class QuoteLinks
{
    public static function expiresAt(Quote $quote): CarbonImmutable
    {
        $valid = $quote->valid_until !== null ? CarbonImmutable::parse($quote->valid_until)->endOfDay() : CarbonImmutable::now();

        return $valid->max(CarbonImmutable::now())->addDays(60);
    }

    /** Absolute link to share with the client. */
    public static function portal(Quote $quote): string
    {
        return url(self::signed('quotes.portal', $quote));
    }

    public static function signed(string $route, Quote $quote): string
    {
        return URL::temporarySignedRoute($route, self::expiresAt($quote), ['quote' => $quote->public_id], absolute: false);
    }

    /** "Share on WhatsApp" by hand, with the client's number and a ready message — the fallback when the API is off. */
    public static function whatsappShare(Quote $quote): ?string
    {
        return Phone::whatsappUrl($quote->contact->whatsappNumber(), self::whatsappBody($quote));
    }

    /**
     * The message that carries a quotation to the client (QUO-008, D-111).
     *
     * The link, not the PDF: a link always opens the latest revision, keeps working after a revision, and lets the
     * client accept, ask for changes or ask a question from the same place.
     */
    public static function whatsappBody(Quote $quote): string
    {
        $total = Money::ofMinor((int) $quote->total_minor)->format(lakhGrouping: true);
        $valid = $quote->valid_until !== null ? CarbonImmutable::parse($quote->valid_until)->format('j M Y') : null;

        return implode("\n", array_filter([
            "Assalam o Alaikum {$quote->contact->firstName()}, here is your quotation {$quote->label()} from Woodex"
                ." for \"{$quote->title}\".",
            'Total: '.$total.($valid !== null ? ", valid until {$valid}." : '.'),
            self::portal($quote),
            'Open it to see the details, download the PDF and accept — or reply here with any question.',
        ]));
    }

    /**
     * Owners, the quote owner and the lead owner (active, de-duplicated).
     *
     * @return Collection<int, User>
     */
    public static function watchers(Quote $quote): Collection
    {
        $ids = array_filter([$quote->owner_id, $quote->lead?->owner_id]);

        return User::query()
            ->with('telegramLink')
            ->where('is_active', true)
            ->where(fn ($query) => $query->role('owner')->orWhereIn('id', $ids))
            ->get();
    }

    /**
     * Who may approve: owners and roles with quotes.approve (sales managers), never the requester themselves.
     *
     * @return Collection<int, User>
     */
    public static function approvers(?User $except = null): Collection
    {
        return User::query()
            ->with('telegramLink')
            ->where('is_active', true)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->id))
            ->get()
            ->filter(fn (User $user): bool => $user->can('quotes.approve'))
            ->values();
    }
}
