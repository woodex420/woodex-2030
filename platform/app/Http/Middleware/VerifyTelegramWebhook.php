<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Authenticate before applying actor rate limits. New registrations never put a secret in the URL. */
final class VerifyTelegramWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.telegram.webhook_secret');
        $legacy = $request->route('secret');
        abort_if($expected === '' || ($legacy !== null && (! is_string($legacy) || ! hash_equals($expected, $legacy))), 404);

        $header = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        $legacyAllowed = $legacy !== null && (bool) config('services.telegram.allow_legacy_webhook', false);
        abort_unless(($header !== '' && hash_equals($expected, $header)) || ($header === '' && $legacyAllowed), 403);

        return $next($request);
    }
}
