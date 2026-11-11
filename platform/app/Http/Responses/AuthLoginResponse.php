<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Http\Responses\LoginResponse as NativeLoginResponse;
use Symfony\Component\HttpFoundation\Response;

/** Keep native Fortify responses; enhanced sign-in must not follow proxy-generated absolute redirects. */
final class AuthLoginResponse implements LoginResponse
{
    /** @param Request $request */
    public function toResponse($request): Response
    {
        if (! $request->wantsJson()) {
            return (new NativeLoginResponse)->toResponse($request);
        }
        $fallback = route('admin.dashboard', [], false);
        $intended = $request->session()->pull('url.intended', $fallback);
        $redirect = self::relativeDestination($intended, $request->getHost(), (string) config('app.url')) ?? $fallback;

        return response()->json(['two_factor' => false, 'redirect' => $redirect]);
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
