<?php

declare(strict_types=1);

namespace App\Domain\Bot;

/**
 * What a provider came back with (BOT-002). `tokens` is the provider's own accounting; a keyless
 * reply carries none, because nothing was spent to make it.
 */
final class BotReply
{
    public function __construct(
        public readonly string $text,
        public readonly int $tokensIn = 0,
        public readonly int $tokensOut = 0,
        public readonly bool $ok = true,
        public readonly ?string $error = null,
    ) {}

    public static function failed(string $error): self
    {
        return new self('', ok: false, error: $error);
    }

    public function tokens(): int
    {
        return $this->tokensIn + $this->tokensOut;
    }
}
