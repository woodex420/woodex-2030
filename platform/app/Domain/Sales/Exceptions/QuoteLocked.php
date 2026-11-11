<?php

declare(strict_types=1);

namespace App\Domain\Sales\Exceptions;

use App\Domain\Sales\Models\Quote;
use RuntimeException;

/**
 * Thrown when something tries to change a quote that is no longer a draft (sent quotes are revised, QUO-013).
 */
final class QuoteLocked extends RuntimeException
{
    public static function for(Quote $quote): self
    {
        return new self("{$quote->label()} is {$quote->status->label()} and can no longer be edited.");
    }
}
