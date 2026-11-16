<?php

declare(strict_types=1);

namespace App\Domain\Website\Services;

use App\Domain\Website\Models\Page;
use Illuminate\Support\Facades\Cache;

/**
 * Full-page cache for published pages (docs/09 §2.3): shared hosting has no Varnish, and these pages change only
 * when someone publishes. The key carries `updated_at`, so publishing a page (which touches it) makes a new key —
 * no flushing needed, and a visitor never sees a half-published page.
 */
final class PageCache
{
    public static function key(Page $page): string
    {
        // Microseconds: a publish and a page request can land in the same second.
        return sprintf('website.page.%d.%s', $page->id, $page->updated_at?->format('U.u') ?? '0');
    }

    public static function html(Page $page, callable $render, bool $cacheable = true): string
    {
        // Two reasons to render instead of cache: caching is switched off, or a signed-in member of staff is
        // looking — their page is not the visitors' page (D-69), and it must not become it for a day.
        if (! $cacheable || ! (bool) config('website.cache', true)) {
            return (string) $render();
        }

        /** @var string $html */
        $html = Cache::remember(self::key($page), now()->addDay(), fn (): string => (string) $render());

        return $html;
    }

    public static function forget(Page $page): void
    {
        Cache::forget(self::key($page));
    }

    /**
     * Called after an image changed: every published page that shows it has to be rendered again.
     *
     * @param  iterable<Page>  $pages
     */
    public static function invalidateAll(iterable $pages): void
    {
        foreach ($pages as $page) {
            self::invalidate($page);
        }
    }

    /**
     * Called after any change to a page or to one of its blocks. The old entry is dropped first (the key still
     * holds the old timestamp at that moment), then the page is touched so the next render writes a new key.
     */
    public static function invalidate(Page $page): void
    {
        self::forget($page);
        $page->touch();
    }
}
