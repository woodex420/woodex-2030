<?php

declare(strict_types=1);

namespace App\Domain\Bot\Contracts;

use App\Domain\Bot\BotReply;

/**
 * One assistant's brain behind one name (BOT-002). The studio picks a provider in Settings → Bot;
 * the engine never knows which. Every provider gets the same conversation and must live with the
 * same guardrails on the way out.
 */
interface BotProvider
{
    /** A key (or whatever the provider needs) is present: this brain can actually think. */
    public function isConfigured(): bool;

    public function name(): string;

    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages  The conversation so far, oldest first
     */
    public function complete(string $system, array $messages): BotReply;
}
