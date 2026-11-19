<?php

declare(strict_types=1);

namespace App\Support\Api;

/** What a create with a key came to: now, or the first time it was sent (App\Support\Api\Idempotency). */
final readonly class IdempotentOutcome
{
    /** @param  array<string, mixed>  $kept  what the answer is made from */
    public function __construct(public int $status, public array $kept, public bool $replayed) {}

    /**
     * The header that tells a client this answer was given before and nothing was written now.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->replayed ? ['Idempotent-Replayed' => 'true'] : [];
    }
}
