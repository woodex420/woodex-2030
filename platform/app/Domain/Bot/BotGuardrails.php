<?php

declare(strict_types=1);

namespace App\Domain\Bot;

/**
 * BOT-007: the guardrails every reply passes on its way out — checked in code, not just asked
 * for in the prompt, because "the model was told" is not a control. A violation never reaches
 * the visitor: the reply is replaced with the safe sentence and the thread is handed to a human.
 */
final class BotGuardrails
{
    /**
     * Check one reply against the knowledge it was allowed to use.
     *
     * @param  list<string>  $knowledge  The texts that were fed to the model as context
     * @return list<string> what was violated, empty when the reply is safe
     */
    public function violations(string $reply, array $knowledge): array
    {
        $violations = [];
        $allowed = mb_strtolower(implode("\n", $knowledge));

        // A date or deadline promise: timelines are the team's to confirm, never the bot's.
        if (preg_match('/\b(by|before|on|within)\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday|next week|next month|\d+\s*(to|-|–)?\s*\d*\s*(day|days|week|weeks|month|months)|tomorrow|tomorrow)\b/i', $reply) === 1
            || preg_match('/\b(deliver|install|complete|finish|ready)\w*\s+(by|on|before|within)\b/i', $reply) === 1) {
            $violations[] = 'promised a timeline';
        }

        // A discount or a price cut: never the bot's to offer.
        if (preg_match('/\b(discount|discounts|% ?off|percent off|price cut|cheaper price|reduce the price|lower the price)\b/i', $reply) === 1) {
            $violations[] = 'offered a discount';
        }

        // A price the knowledge does not carry: the only numbers the bot may say are the studio's
        // own — compared digit-to-digit, so "150,000" in the knowledge covers "Rs 150000".
        $allowedDigits = (string) preg_replace('/\D/', '', $allowed);

        if (preg_match_all('/(?:rs\.?|pkr|₨)\s*[\d,]{3,}|\b[\d,]{5,}\s*(?:rupees|rs)\b/i', $reply, $prices) !== false) {
            foreach ($prices[0] as $price) {
                $digits = preg_replace('/\D/', '', $price);

                if ($digits !== '' && ! str_contains($allowedDigits, $digits)) {
                    $violations[] = 'quoted a price the knowledge does not carry';

                    break;
                }
            }
        }

        // Contact details that are not the visitor's: the bot never shares anyone's number or email.
        if (preg_match('/\b(?:\+92|0)?3\d{2}[- ]?\d{7}\b|\b[\w.+-]+@[\w-]+\.[a-z]{2,}\b/i', $reply) === 1) {
            $violations[] = 'shared a phone number or email';
        }

        return $violations;
    }

    /** What the visitor gets instead of a violating reply. */
    public function safeReply(): string
    {
        return 'For exact prices and timelines, our team will confirm with you personally — a designer will reply here shortly.';
    }
}
