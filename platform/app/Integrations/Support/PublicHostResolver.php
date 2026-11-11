<?php

declare(strict_types=1);

namespace App\Integrations\Support;

/** Separate resolver so tests can prove public/private DNS decisions without external DNS. */
class PublicHostResolver
{
    /** @return list<string> */
    public function addresses(string $host): array
    {
        return gethostbynamel($host) ?: [];
    }
}
