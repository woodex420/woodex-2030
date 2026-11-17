<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Exceptions;

use RuntimeException;

/**
 * Thrown when the team tries to write a free-form WhatsApp message outside Meta's 24-hour window (CNV-005,
 * WAP-004). Outside the window only an approved template may go out, so the composer has to switch.
 */
final class OutsideWindow extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The 24-hour window has closed. Send an approved template instead.');
    }
}
