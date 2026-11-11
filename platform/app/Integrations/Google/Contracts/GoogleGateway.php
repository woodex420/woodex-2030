<?php

declare(strict_types=1);

namespace App\Integrations\Google\Contracts;

interface GoogleGateway
{
    /** @return array<string, mixed> */
    public function exchange(string $code, string $verifier): array;

    /** @return array<string, mixed> */
    public function profile(string $accessToken): array;

    /** @return array<string, mixed> */
    public function refresh(string $refreshToken): array;

    public function revoke(string $token): bool;
}
