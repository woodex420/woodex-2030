<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Integrations\Google\Contracts\GoogleGateway;
use App\Integrations\Google\GoogleConnection;
use App\Integrations\Google\GoogleIdentity;
use App\Integrations\Google\GoogleOAuth;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Throwable;

final class GoogleOAuthController extends Controller
{
    public function connect(Request $request, GoogleOAuth $oauth): RedirectResponse
    {
        $oauth->authorizeOwner($this->actor($request));
        $this->password($request, 'admin.integrations');
        if (GoogleConnection::query()->exists()) {
            return $this->result('business', 'Disconnect the existing company Google account before connecting another.');
        }

        return $this->start($request, 'business');
    }

    public function link(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_if($actor->requiresTwoFactorAuthentication() && ! $actor->hasTwoFactorEnabled(), 403);
        $this->password($request, 'admin.profile.security');

        return $this->start($request, 'link');
    }

    public function login(Request $request): RedirectResponse
    {
        if (! GoogleOAuth::loginEnabled()) {
            return $this->result('login', 'Google sign-in is not available yet. Use your Woodex password.');
        }
        $request->session()->forget(['login.id', 'login.remember']);

        return $this->start($request, 'login');
    }

    public function callback(Request $request, GoogleGateway $google, GoogleOAuth $oauth): RedirectResponse
    {
        // Consume before any network request: denied/invalid/expired/replayed callbacks cannot reuse state.
        $context = $request->session()->pull('google.oauth');
        $purpose = is_array($context) && in_array($context['purpose'] ?? '', ['business', 'link', 'login'], true)
            ? $context['purpose'] : 'login';
        try {
            if (! GoogleOAuth::configured() || ! is_array($context)
                || ! is_string($request->query('state')) || ! is_string($context['state'] ?? null)
                || ! hash_equals($context['state'], $request->query('state'))
                || ! is_int($context['issued_at'] ?? null) || $context['issued_at'] < now()->subMinutes(10)->timestamp
                || $context['issued_at'] > now()->timestamp || ! is_string($context['verifier'] ?? null)
                || ! is_string($request->query('code')) || strlen($request->query('code')) > 4096
                || $request->query('code') === '' || $request->has('error')) {
                return $this->result($purpose, 'Google authorization was cancelled, expired or invalid. Please start again.');
            }
            if ($purpose !== 'login') {
                $actor = $this->actor($request);
                abort_unless($actor->is_active && $actor->id === ($context['user_id'] ?? null), 403);
                abort_if($actor->requiresTwoFactorAuthentication() && ! $actor->hasTwoFactorEnabled(), 403);
                if ($purpose === 'business') {
                    $oauth->authorizeOwner($actor);
                }
            } else {
                abort_unless(GoogleOAuth::loginEnabled() && $request->user() === null, 403);
            }
            $tokens = $google->exchange($request->query('code'), $context['verifier']);
            $profile = $oauth->verifiedProfile($tokens);
            if ($purpose === 'business') {
                $oauth->connect($this->actor($request), $profile, $tokens);

                return $this->result($purpose, 'Company Google account connected. Basic identity access only; Workspace tools are not enabled.');
            }
            if ($purpose === 'link') {
                $oauth->link($this->actor($request), $profile);

                return $this->result($purpose, 'Google identity linked for staff sign-in. Your Woodex password and 2FA remain unchanged.');
            }
            // Never auto-register, match by email, assign roles or treat a company connection as a login identity.
            $user = GoogleIdentity::query()->with('user')->where('google_subject', $profile['sub'])->first()?->user;
            if ($user === null || ! $user->is_active) {
                return $this->result('login', 'This Google account cannot sign in. Use your Woodex password and link Google from Security first.');
            }
            $request->session()->regenerate();
            $oauth->audit('login.verified', $user->id);
            if ($user->hasTwoFactorEnabled()) {
                $request->session()->put(['login.id' => $user->id, 'login.remember' => false,
                    'url.intended' => route('admin.dashboard')]);
                TwoFactorAuthenticationChallenged::dispatch($user);

                return redirect()->route('two-factor.login')->header('Cache-Control', 'no-store');
            }
            Auth::guard('web')->login($user, false);
            $request->session()->regenerate();

            return redirect()->route('admin.dashboard')->header('Cache-Control', 'no-store');
        } catch (Throwable) {
            // Do not flash provider errors, codes, tokens, exception messages or credentials.
            try {
                $oauth->audit('oauth.failed', $request->user()?->id, false);
            } catch (Throwable) {
                // An audit/storage outage must not expose secret-bearing exception details.
            }

            return $this->result($purpose, 'Google connection could not be completed. Check setup and permissions, then try again.');
        }
    }

    public function disconnect(Request $request, GoogleOAuth $oauth): RedirectResponse
    {
        $oauth->authorizeOwner($this->actor($request));
        $this->password($request, 'admin.integrations');
        $ok = $oauth->disconnect($this->actor($request));

        return $this->result('business', $ok ? 'Company Google connection revoked and removed.'
            : 'Google revocation could not be confirmed. The connection is disabled locally; retry Disconnect.');
    }

    public function check(Request $request, GoogleOAuth $oauth): RedirectResponse
    {
        $ok = $oauth->check($this->actor($request));

        return $this->result('business', $ok ? 'Google connection verified. Basic identity access only.'
            : 'Google access could not be verified. Finish disconnecting, or disconnect and reconnect.');
    }

    public function unlink(Request $request, GoogleOAuth $oauth): RedirectResponse
    {
        $this->password($request, 'admin.profile.security');
        $request->session()->forget('google.oauth');
        $oauth->unlink($this->actor($request));

        return $this->result('link', 'Google sign-in unlinked. You can still use your Woodex password.');
    }

    private function start(Request $request, string $purpose): RedirectResponse
    {
        if (! GoogleOAuth::configured()) {
            return $this->result($purpose, 'Google setup is required. Ask the owner to register the OAuth app and configure it privately.');
        }
        $state = bin2hex(random_bytes(32));
        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $request->session()->put('google.oauth', ['state' => $state, 'verifier' => $verifier,
            'issued_at' => now()->timestamp, 'purpose' => $purpose, 'user_id' => $request->user()?->id]);
        $params = ['client_id' => config('services.google.client_id'), 'redirect_uri' => config('services.google.redirect_uri'),
            'response_type' => 'code', 'scope' => 'openid email profile', 'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256', 'prompt' => $purpose === 'business' ? 'consent select_account' : 'select_account'];
        if ($purpose === 'business') {
            $params['access_type'] = 'offline';
        }

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986))
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    private function password(Request $request, string $route): void
    {
        try {
            $request->validate(['password' => ['required', 'string', 'current_password:web']]);
        } catch (ValidationException $e) {
            $e->redirectTo(route($route));
            throw $e;
        }
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_active, 403);

        return $user;
    }

    private function result(string $purpose, string $message): RedirectResponse
    {
        $route = match ($purpose) {
            'business' => 'admin.integrations', 'link' => 'admin.profile.security', default => 'login',
        };

        return redirect()->route($route)->with('google_status', $message)
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
