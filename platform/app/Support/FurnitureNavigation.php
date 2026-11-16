<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Addresses of screens in the Agency OS dashboard (the React application), for work that has not moved
 * into this application yet.
 *
 * Which destinations those are is recorded once, in {@see AdminNavigation::registry()} (`legacy`). They
 * are shown on the Feature status screen, never in the sidebar. This class only builds the address, and
 * refuses any configured base that is not a plain HTTPS URL.
 */
final class FurnitureNavigation
{
    /**
     * The address of a screen in the Agency OS dashboard, or null when no safe base address is configured.
     * The two applications do not share a sign-in: the link opens the other one's own.
     */
    public static function url(string $path): ?string
    {
        $base = rtrim((string) config('woodex.legacy_dashboard_url'), '/');
        $parts = parse_url($base);

        // Operator-controlled HTTPS URL, including the dashboard base path. Never accept script URLs or credentials.
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || filter_var($base, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $base.'/'.ltrim($path, '/');
    }
}
