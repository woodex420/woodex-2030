<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Integrations\Mcp\McpAccessToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateMcpBridge
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isProduction() && ! $request->isSecure()) {
            abort(400, 'HTTPS is required.');
        }
        $plain = (string) $request->bearerToken();
        abort_unless(preg_match('/^wx_mcp_[a-f0-9]{64}$/D', $plain) === 1, 401, 'A valid bridge key is required.');
        $token = McpAccessToken::query()->with('user')->where('token_hash', hash('sha256', $plain))->first();
        abort_unless($token !== null && $token->revoked_at === null && $token->expires_at->isFuture()
            && $token->user !== null && $token->user->is_active, 401, 'Bridge key is expired, revoked or invalid.');

        // Keep per-key limiting after authentication; Laravel may reorder route throttle middleware.
        $limitKey = 'mcp-key:'.$token->id;
        if (RateLimiter::tooManyAttempts($limitKey, 60)) {
            return response()->json(['message' => 'Bridge rate limit reached.'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($limitKey));
        }
        RateLimiter::hit($limitKey, 60);

        // A key never grants more than its issuing user's current permissions and visibility.
        Auth::shouldUse('web');
        Auth::guard()->setUser($token->user);
        $request->setUserResolver(fn () => $token->user);
        $request->attributes->set('mcp_token', $token);
        $token->forceFill(['last_used_at' => now()])->save();
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
