<?php

declare(strict_types=1);

namespace App\Integrations\Support;

/**
 * Only public https hosts may receive a credential or a webhook: a user-supplied URL must never become an
 * SSRF probe into the private network. Shared by the Custom API connector and outbound webhooks.
 */
final class PublicUrlGuard
{
    public static function isSafe(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (isset($parts['port']) && $parts['port'] !== 443) {
            return false;
        }

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return str_contains($host, '.');
    }

    /**
     * Resolve and pin the actual connection to a public address, preserving the original TLS host.
     * Re-run on every attempt: DNS rebinding and redirects must not bypass the URL check.
     *
     * @return array<string, mixed>
     */
    public static function requestOptions(string $url): array
    {
        if (! self::isSafe($url)) {
            throw new \RuntimeException('The destination must be a public HTTPS URL.');
        }
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host] : app(PublicHostResolver::class)->addresses($host);
        if ($ips === []) {
            throw new \RuntimeException('The destination hostname did not resolve.');
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new \RuntimeException('The destination resolves to a private or reserved address.');
            }
        }

        $address = str_contains($ips[0], ':') ? '['.$ips[0].']' : $ips[0];

        return ['curl' => [CURLOPT_RESOLVE => [$host.':443:'.$address], CURLOPT_PROXY => '']];
    }
}
