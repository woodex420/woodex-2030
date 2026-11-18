<?php

declare(strict_types=1);

namespace App\Integrations\Turnstile;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cloudflare Turnstile server-side check for public forms and chat (CAP-002).
 * Legacy callers retain their original optional/fail-open policy. V1 capture explicitly requests fail-closed:
 * provider exceptions fail verification, and a missing secret is allowed only in local/testing environments.
 */
final class TurnstileVerifier
{
    public function enabled(): bool
    {
        return filled(config('services.turnstile.secret_key'));
    }

    public function verify(?string $token, ?string $ip = null, bool $failClosed = false): bool
    {
        if (! $this->enabled()) {
            return ! $failClosed || app()->environment('local', 'testing');
        }

        if ($token === null || $token === '') {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(5)->post((string) config('services.turnstile.verify_url'), [
                'secret' => config('services.turnstile.secret_key'),
                'response' => $token,
                'remoteip' => $ip,
            ]);

            return $response->json('success') === true;
        } catch (Throwable $exception) {
            report($exception);

            return ! $failClosed;
        }
    }
}
