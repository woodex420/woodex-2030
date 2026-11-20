<?php

declare(strict_types=1);

namespace App\Domain\System\Import\OldSystem;

/**
 * The answer of {@see Refs} about one row of the old system and one table of the admin: whether the row was
 * brought over, whether it changed since, and the number of the admin's record when there is one.
 */
final readonly class Answer
{
    public function __construct(
        public State $state,
        /** The number of the record in the admin's table; null while the row is new and nothing was written. */
        public ?int $record,
        /** The name the row is entered under in the table of the old numbers ({@see Refs::name()}). */
        public string $name,
        /** The row's key as it is entered there ({@see Refs::key()}). */
        public string $key,
    ) {}

    public function isNew(): bool
    {
        return $this->state === State::New;
    }

    public function changed(): bool
    {
        return $this->state === State::Changed;
    }
}
