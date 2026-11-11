<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Exceptions;

use RuntimeException;

final class ConsentRequired extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Sending blocked: this contact opted out or the required WhatsApp consent is missing. No message was sent.');
    }
}
