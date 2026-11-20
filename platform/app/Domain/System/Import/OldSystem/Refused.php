<?php

declare(strict_types=1);

namespace App\Domain\System\Import\OldSystem;

use DomainException;

/**
 * A value of the old system that the admin does not take, said by name: what it was meant to be, the value, and
 * why. Nothing is rounded, cut, guessed or left out quietly to make a row fit: an importer stops here, and its
 * dry run lists these (master list T-176, T-177, T-179).
 */
final class Refused extends DomainException
{
    public function __construct(
        /** What the value was meant to be: 'time', 'money', 'JSON', 'yes or no', 'word', 'text', 'key', 'name'. */
        public readonly string $what,
        /** The value as it came, cut to sixty characters so that a long cell does not fill a log. */
        public readonly string $value,
        public readonly string $why,
    ) {
        parent::__construct("{$what} refused: {$this->value}: {$why}");
    }

    public static function of(string $what, mixed $value, string $why): self
    {
        $shown = match (true) {
            $value === null => '(nothing)',
            is_bool($value) => $value ? 'true' : 'false',
            is_float($value) => (string) json_encode($value),
            is_scalar($value) => (string) $value,
            default => get_debug_type($value),
        };

        return new self($what, mb_strlen($shown) > 60 ? mb_substr($shown, 0, 60).'…' : $shown, $why);
    }
}
