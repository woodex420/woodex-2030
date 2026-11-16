<?php

declare(strict_types=1);

namespace App\Integrations\Connectors;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * The `state` of a connector round trip: encrypted and short-lived, so a callback cannot be replayed or pointed at
 * another provider. It names the provider and the person who started the connection; nothing else is trusted from
 * the browser.
 */
final class OAuthState
{
    private const int TTL = 600;

    public static function issue(string $provider, int $userId): string
    {
        return Crypt::encryptString((string) json_encode([
            'provider' => $provider, 'user_id' => $userId, 'expires' => now()->addSeconds(self::TTL)->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{provider: string, user_id: int}|null */
    public static function pull(string $state): ?array
    {
        try {
            $data = json_decode(Crypt::decryptString($state), true, 4, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            Log::notice('Connector callback with an unreadable state.', ['reason' => $exception->getMessage()]);

            return null;
        }

        if (! is_array($data) || ! is_string($data['provider'] ?? null) || ! is_int($data['user_id'] ?? null)
            || ! is_int($data['expires'] ?? null) || $data['expires'] < now()->getTimestamp()) {
            return null;
        }

        return ['provider' => $data['provider'], 'user_id' => $data['user_id']];
    }
}
