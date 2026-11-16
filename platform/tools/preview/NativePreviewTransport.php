<?php

declare(strict_types=1);

namespace Woodex\Sandbox;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/** Private launcher only. Keep navigation inside the authorized preview origin; never handle traffic tokens. */
final class NativePreviewTransport
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(NativeDirectPreview::allowed(), 503);

        return self::relativeResponse($next($request), (string) config('app.url'));
    }

    public static function relativeResponse(Response $response, string $origin): Response
    {
        if ($response instanceof RedirectResponse) {
            $response->setTargetUrl(self::relative($response->getTargetUrl(), $origin));
        }
        foreach (['Location', 'Link'] as $header) {
            if ($response->headers->has($header)) {
                $response->headers->set($header, self::relative((string) $response->headers->get($header), $origin));
            }
        }
        $type = strtolower((string) $response->headers->get('Content-Type'));
        if (str_starts_with($type, 'text/html') || str_starts_with($type, 'application/json') || str_starts_with($type, 'application/javascript')) {
            $body = $response->getContent();
            if (is_string($body)) {
                if (str_starts_with($type, 'application/json')) {
                    // Livewire snapshots are signed. Rewrite effects, never signed component state.
                    $payload = json_decode($body, true);
                    if (is_array($payload) && isset($payload['components']) && is_array($payload['components'])) {
                        foreach ($payload['components'] as &$component) {
                            if (is_array($component) && isset($component['effects']) && is_array($component['effects'])) {
                                array_walk_recursive($component['effects'], static function (&$value) use ($origin): void {
                                    if (is_string($value)) {
                                        $value = self::relativeHtml($value, $origin);
                                    }
                                });
                            }
                        }
                        unset($component);
                        $body = json_encode($payload, JSON_THROW_ON_ERROR);
                    }
                } else {
                    $body = self::relativeHtml($body, $origin);
                }
                $response->setContent($body);
                $response->headers->remove('Content-Length');
            }
        }

        return $response;
    }

    public static function relativeHtml(string $value, string $origin): string
    {
        $snapshots = [];
        $prefix = 'WOODExSnapshot'.bin2hex(random_bytes(16));
        $value = (string) preg_replace_callback('/\bwire:snapshot="[^"]*"/', static function (array $match) use (&$snapshots, $prefix): string {
            $key = $prefix.count($snapshots);
            $snapshots[$key] = $match[0];

            return $key;
        }, $value);

        return strtr(self::relative($value, $origin), $snapshots);
    }

    public static function relative(string $value, string $origin): string
    {
        $parts = parse_url($origin);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return $value;
        }
        $origin = rtrim($origin, '/');
        foreach ([$origin, str_replace('/', '\\/', $origin)] as $prefix) {
            // Exact configured origin only: never rewrite a foreign host, including prefix lookalikes.
            $value = (string) preg_replace('~'.preg_quote($prefix, '~').'(?=(?:\\\\?/|[?#"\'<>\s]|$))~', '', $value);
        }

        return $value;
    }
}
