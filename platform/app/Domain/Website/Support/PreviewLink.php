<?php

declare(strict_types=1);

namespace App\Domain\Website\Support;

use App\Domain\Website\Models\Page;
use DateTimeInterface;

/**
 * A shareable draft-preview link (V2-2, docs/12 §5.3): the owner opens a draft on their phone without a
 * staff login. The token is a signature over the page's slug and the expiry — nothing is stored, nothing
 * to clean up, and the link dies on its own. SiteRouter accepts it next to the staff-session preview.
 */
final class PreviewLink
{
    /**
     * The URL for the page's draft, valid until $expires.
     */
    public static function make(Page $page, DateTimeInterface $expires): string
    {
        $expiresAt = $expires->getTimestamp();

        return Page::urlFor($page->path()).'?'.http_build_query([
            'preview' => '1',
            'expires' => $expiresAt,
            'token' => self::signature($page, $expiresAt),
        ]);
    }

    /**
     * Does this token open this page's draft right now?
     */
    public static function valid(Page $page, ?string $token, ?int $expires): bool
    {
        if ($token === null || $token === '' || $expires === null) {
            return false;
        }

        if ($expires < time()) {
            return false;
        }

        return hash_equals(self::signature($page, $expires), $token);
    }

    private static function signature(Page $page, int $expires): string
    {
        return hash_hmac('sha256', $page->slug.'|'.$expires, (string) config('app.key'));
    }
}
