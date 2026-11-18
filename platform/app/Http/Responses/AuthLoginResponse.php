<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\User;
use App\Support\WorkspacePreferences;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where sign-in takes a person: the link they followed before signing in, else the screen they chose on Workspace
 * preferences, else the dashboard. A first sign-in is led to the welcome (master list T-056).
 *
 * The answer is always a path of this site: the enhanced sign-in must not follow an absolute address that a proxy
 * in front of the application could have shaped.
 */
final class AuthLoginResponse implements LoginResponse
{
    /** @param Request $request */
    public function toResponse($request): Response
    {
        $redirect = self::destination($request);

        return $request->wantsJson()
            ? response()->json(['two_factor' => false, 'redirect' => $redirect])
            : redirect()->to($redirect);
    }

    /** The path this request goes to after a sign-in that succeeded. */
    public static function destination(Request $request): string
    {
        $user = $request->user();
        $home = $user instanceof User ? WorkspacePreferences::home($user, $request) : route('admin.dashboard', [], false);
        if (! $request->hasSession()) {
            return $home;
        }

        $intended = $request->session()->pull('url.intended', $home);
        // A first sign-in is led to the welcome, whatever link was followed before it.
        if ($request->session()->get(WorkspacePreferences::FIRST_SIGN_IN) === true) {
            return $home;
        }

        return self::relativeDestination($intended, $request->getHost(), (string) config('app.url')) ?? $home;
    }

    public static function relativeDestination(mixed $value, string $requestHost, string $appUrl): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) {
            return null;
        }
        if (str_starts_with($value, '/')) {
            return str_starts_with($value, '//') ? null : $value;
        }
        $url = parse_url($value);
        $appHost = parse_url($appUrl, PHP_URL_HOST);
        if (! is_array($url) || ! in_array(strtolower($url['scheme'] ?? ''), ['http', 'https'], true)
            || isset($url['user']) || isset($url['pass'])
            || ! in_array(strtolower($url['host'] ?? ''), [strtolower($requestHost), is_string($appHost) ? strtolower($appHost) : ''], true)) {
            return null;
        }
        $path = $url['path'] ?? '/';
        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }

        return $path.(isset($url['query']) ? '?'.$url['query'] : '').(isset($url['fragment']) ? '#'.$url['fragment'] : '');
    }
}
