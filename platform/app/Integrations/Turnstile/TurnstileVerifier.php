<?php

declare(strict_types=1);

namespace App\Integrations\Turnstile;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cloudflare Turnstile server-side check for public forms and chat (CAP-002).
 * Disabled when no secret is configured (local dev). Fails OPEN on network errors:
 * losing a genuine enquiry is worse than letting one spam message through (throttle + honeypot still apply).
 */
final class TurnstileVerifier
{
    public function enabled(): bool
    {
        return filled(config('services.turnstile.secret_key'));
    }

    public function verify(?string $token, ?string $ip = null): bool
    {
        if (! $this->enabled()) {
            return true;
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

            return true;
        }
    }
}
