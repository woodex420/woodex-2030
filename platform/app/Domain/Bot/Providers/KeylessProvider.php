<?php

declare(strict_types=1);

namespace App\Domain\Bot\Providers;

use App\Domain\Bot\BotReply;
use App\Domain\Bot\Contracts\BotProvider;

/**
 * The keyless brain (BOT-002/008): no model, no key, no cost. Every question gets an honest
 * "a human will reply" — in the visitor's own script — and the thread is handed to the team.
 * This is the default until the owner funds a model, and the fallback when the day's cap is hit.
 */
final class KeylessProvider implements BotProvider
{
    public function isConfigured(): bool
    {
        return true; // it never calls anyone; it is always "configured" to be honest
    }

    public function name(): string
    {
        return 'none';
    }

    public function complete(string $system, array $messages): BotReply
    {
        $last = (string) ($messages[count($messages) - 1]['content'] ?? '');

        $urdu = preg_match('/[\x{0600}-\x{06FF}]/u', $last) === 1;
        $roman = preg_match('/\b(kya|kaise|kab|kitna|kitne|chahiye|bataye|batao|chahiye)\b/i', $last) === 1;

        return new BotReply($urdu
            ? 'شکریہ! آپ کی بات نوٹ کر لی گئی ہے — ہماری ٹیم یہیں جواب دے گی۔'
            : ($roman
                ? 'Shukriya! Aap ki baat note kar li hai — hamari team yahin jawab degi.'
                : 'Thank you! Our team has your message and will reply right here.'));
    }
}
