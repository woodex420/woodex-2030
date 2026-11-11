<?php

declare(strict_types=1);

namespace App\Domain\Crm\Exceptions;

use App\Domain\Crm\Models\Contact;
use RuntimeException;

final class DuplicateContact extends RuntimeException
{
    public function __construct(public readonly Contact $existing, public readonly string $field)
    {
        parent::__construct("A contact with this {$field} already exists: {$existing->name}.");
    }
}
