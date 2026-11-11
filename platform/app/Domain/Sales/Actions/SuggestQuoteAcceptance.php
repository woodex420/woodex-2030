<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Conversations\Enums\MessageDirection;
use App\Domain\Conversations\Models\Message;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Notifications\QuoteNotification;
use App\Domain\Sales\Services\QuoteLinks;
use App\Domain\System\Alerts\AlertRouter;

/**
 * "The client may have accepted" (D-112).
 *
 * A client answers a quotation on WhatsApp with "ok" or "theek hai" far more often than they press the Accept
 * button. That reply must not accept a price by itself — "ok" is also what someone types while asking for a change
 * — so this action only raises its hand: it remembers the message, writes it on the quote's timeline and tells the
 * team, who press Accept if it really was a yes.
 *
 * Only short, whole replies count. "Ok, but can we change the wardrobe?" is a change request, not an acceptance.
 */
final class SuggestQuoteAcceptance
{
    /** Replies that read like a yes, in the words clients here actually write. */
    private const array SOUNDS_LIKE_YES = [
        'ok', 'okay', 'okk', 'okie', 'yes', 'yep', 'yeah', 'yea', 'yup', 'sure', 'fine',
        'accept', 'accepted', 'acceptable', 'approve', 'approved', 'confirm', 'confirmed', 'agreed', 'agreed ',
        'go ahead', 'go ahead please', 'lets do it', "let's do it", 'proceed', 'please proceed', 'start', 'start it',
        'done', 'deal', 'done deal', 'perfect', 'sounds good', 'looks good',
        'haan', 'han', 'ji haan', 'ji han', 'g haan', 'theek hai', 'theek', 'thik hai', 'thik', 'bilkul',
        'zaroor', 'zarur', 'kar dein', 'kar do', 'shuru', 'shuru kar dein', 'ho gaya', 'approved hai',
        '👍', '👌', '🙌',
    ];

    /** Longer than this and it is a conversation, not a yes. */
    private const int MAX_LENGTH = 40;

    public function __construct(private readonly LogQuoteEvent $log) {}

    public function handle(Message $message): ?Quote
    {
        if ($message->direction !== MessageDirection::In || ! $this->soundsLikeAcceptance((string) $message->body)) {
            return null;
        }

        $contact = $message->conversation?->contact;

        if ($contact === null) {
            return null;
        }

        $quote = Quote::query()
            ->where('contact_id', $contact->id)
            ->whereIn('status', [QuoteStatus::Sent, QuoteStatus::Viewed])
            ->whereNotNull('sent_at')
            ->where('sent_at', '<=', $message->created_at ?? now())
            ->orderByDesc('sent_at')
            ->first();

        if ($quote === null || $quote->acceptance_hint_at !== null) {
            return null;
        }

        $quote->forceFill([
            'acceptance_hint_at' => now(),
            'acceptance_hint_message_id' => $message->id,
        ])->save();

        $reply = trim((string) $message->body);

        $this->log->handle(
            $quote,
            'acceptance_suggested',
            null,
            "{$quote->contact->name} replied on WhatsApp: “{$reply}”. Check it, then accept the quotation if it is a yes.",
            ['message_id' => $message->id, 'reply' => $reply],
        );

        app(AlertRouter::class)->send('quote.accepted', QuoteLinks::watchers($quote), new QuoteNotification(
            $quote,
            'acceptance_suggested',
            "“{$reply}” on WhatsApp — check it, then accept if it is a yes.",
        ));

        return $quote;
    }

    /** @internal */
    public function soundsLikeAcceptance(string $body): bool
    {
        $body = trim(mb_strtolower($body));
        $body = trim(preg_replace('/[\s.!]+$/u', '', $body) ?? '');

        if ($body === '' || mb_strlen($body) > self::MAX_LENGTH) {
            return false;
        }

        // A question is never a yes: "ok, what is the delivery time?" is still a question about the quotation.
        if (str_contains($body, '?') || str_contains($body, '؟')) {
            return false;
        }

        // Words only: "Ok 👍" and "yes please" count; "ok but change the wardrobe" does not.
        $words = trim(preg_replace('/[^a-z0-9\s\x{0620}-\x{06FF}]+/u', ' ', $body) ?? '');
        $words = trim(preg_replace('/\s+/u', ' ', $words) ?? '');

        if ($words === '') {
            // Nothing but a gesture: a thumbs-up is a yes in any language.
            return in_array($body, ['👍', '👌', '🙌', '✅', '👏'], true);
        }

        if (in_array($words, self::SOUNDS_LIKE_YES, true)) {
            return true;
        }

        // "Yes please" and "theek hai jee" are short enough to be a whole answer. A third word is usually a
        // condition or a change, which a person must read — so it is left alone.
        if (count(explode(' ', $words)) <= 3) {
            foreach (self::SOUNDS_LIKE_YES as $phrase) {
                if (str_starts_with($words, $phrase.' ')) {
                    return true;
                }
            }
        }

        return false;
    }
}
