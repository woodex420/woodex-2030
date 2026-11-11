<?php

declare(strict_types=1);

namespace App\Domain\Website\Services;

use App\Domain\Website\Enums\PageType;
use App\Domain\Website\Models\NavigationItem;
use App\Domain\Website\Models\Page;
use Illuminate\Support\Facades\Cache;

/**
 * Menus and the service links used by the header, the footer and the structured data (docs/09 §2.1). Menus are
 * editable in the dashboard; until someone fills them in the header and the footer use the built-in defaults
 * below, so the site always has its structure (docs/09 §2.1, audit of 16 Oct 2026 in docs/10).
 */
final class Menus
{
    /** The six disciplines the studio sells (docs/09 §3, D-68), in the order they are shown. */
    private const array CORE_SERVICES = [
        '/interior-design/',
        '/fit-out/',
        '/renovation/',
        '/architecture/',
        '/3d-visualization/',
        '/furniture/',
    ];

    /**
     * @return list<array{label: string, url: string, target: string}>
     */
    public static function items(string $menu): array
    {
        /** @var list<array{label: string, url: string, target: string}> $items */
        $items = Cache::remember('website.menu.'.$menu, now()->addDay(), fn (): array => NavigationItem::query()
            ->where('menu', $menu)
            ->orderBy('position')
            ->get(['label', 'url', 'target'])
            ->map(fn (NavigationItem $item): array => [
                'label' => $item->label,
                'url' => $item->url,
                'target' => $item->target ?: '_self',
            ])->all());

        return $items === [] ? self::defaults($menu) : $items;
    }

    /**
     * The menu as a tree: top items with their children and grandchildren, ready for dropdowns and
     * mega panels (V2-3). Falls back to the flat built-in defaults — no children — when nobody has
     * filled the menu in, so the header always has its structure.
     *
     * @return list<array{label: string, url: string, target: string, is_mega: bool, children: list<array<string, mixed>>}>
     */
    public static function tree(string $menu): array
    {
        /** @var list<array{label: string, url: string, target: string, is_mega: bool, children: list<array<string, mixed>>}> $tree */
        $tree = Cache::remember('website.menu.tree.'.$menu, now()->addDay(), fn (): array => self::buildTree($menu));

        if ($tree === []) {
            return array_map(
                static fn (array $item): array => ['label' => $item['label'], 'url' => $item['url'], 'target' => $item['target'], 'is_mega' => false, 'children' => []],
                self::defaults($menu),
            );
        }

        return $tree;
    }

    /** @return list<array{label: string, url: string, target: string, is_mega: bool, children: list<array<string, mixed>>}> */
    private static function buildTree(string $menu): array
    {
        $items = NavigationItem::query()
            ->where('menu', $menu)
            ->orderBy('position')
            ->get(['id', 'parent_id', 'label', 'url', 'target', 'is_mega']);

        // Top-level items sit under key 0 (their parent is null); every child sits under its parent's id.
        $byParent = $items->groupBy(fn (NavigationItem $item): string => (string) ($item->parent_id ?? 0));

        $childrenOf = function (string $parentId) use (&$childrenOf, $byParent): array {
            return collect($byParent->get($parentId, []))
                ->map(fn (NavigationItem $item): array => [
                    'label' => $item->label,
                    'url' => $item->url,
                    'target' => $item->target ?: '_self',
                    'is_mega' => (bool) $item->is_mega,
                    'children' => $childrenOf((string) $item->id),
                ])
                ->values()
                ->all();
        };

        return $childrenOf('0');
    }

    /**
     * The navigation a studio's website needs before anybody has opened Website -> Menus.
     *
     * Without this the header and the footer were empty, and with them the site had no structure: 27 of the 89
     * pages (the home page among them) had no link pointing at them anywhere but the sitemap. The owner can
     * replace every line of it in the dashboard; this is only what shows until they do.
     *
     * @return list<array{label: string, url: string, target: string}>
     */
    public static function defaults(string $menu): array
    {
        $link = fn (string $label, string $url): array => ['label' => $label, 'url' => $url, 'target' => '_self'];

        return match ($menu) {
            'header' => [
                $link('Services', '/services/'),
                $link('Cost guides', '/insights/'),
                $link('Projects', '/projects/'),
                $link('About', '/about/'),
                $link('Contact', '/contact/'),
            ],
            'footer' => [
                $link('Interior design', '/interior-design/'),
                $link('Cost guides', '/insights/'),
                $link('Cost estimator', '/estimator/'),
                $link('Projects', '/projects/'),
                $link('Book a visit', '/book-a-visit/'),
                $link('About the studio', '/about/'),
                $link('Contact', '/contact/'),
                $link('Privacy', '/privacy/'),
            ],
            default => [],
        };
    }

    /**
     * The six disciplines, for the footer and the OfferCatalog in the structured data.
     *
     * Since the complete old website arrived there are 60 service pages (every old URL is editable now), but a
     * footer that lists sixty links is not a footer — and an OfferCatalog with sixty offers says nothing. The six
     * the studio actually sells are shown; the rest are reachable from them and from the sitemap.
     *
     * @return list<array{name: string, copy: string, url: string}>
     */
    public static function serviceLinks(): array
    {
        /** @var list<array{name: string, copy: string, url: string}> $links */
        $links = Cache::remember('website.services', now()->addDay(), fn (): array => Page::query()
            ->published()
            ->ofType(PageType::Service)
            ->orderBy('sort')
            ->get()
            ->map(fn (Page $page): array => [
                'name' => $page->title,
                'copy' => (string) $page->excerpt,
                'url' => $page->path(),
            ])->all());

        $core = array_values(array_filter(
            $links,
            fn (array $link): bool => in_array($link['url'], self::CORE_SERVICES, true),
        ));

        if ($core === []) {
            return $links; // A studio that renamed its disciplines still gets its services, in their own order.
        }

        usort($core, fn (array $a, array $b): int => array_search($a['url'], self::CORE_SERVICES, true)
            <=> array_search($b['url'], self::CORE_SERVICES, true));

        return $core;
    }

    /**
     * The cities we work in, for the footer. Before this existed the twelve city pages were reachable from the
     * sitemap and nothing else — every one of them an orphan.
     *
     * @return list<array{name: string, url: string}>
     */
    public static function cityLinks(): array
    {
        /** @var list<array{name: string, url: string}> $links */
        $links = Cache::remember('website.cities', now()->addDay(), fn (): array => Page::query()
            ->published()
            ->ofType(PageType::City)
            ->orderBy('sort')
            ->get()
            ->map(fn (Page $page): array => [
                'name' => $page->title,
                'url' => $page->path(),
            ])->all());

        return $links;
    }

    public static function flush(): void
    {
        Cache::forget('website.services');
        Cache::forget('website.cities');

        foreach (['header', 'footer', 'services'] as $menu) {
            Cache::forget('website.menu.'.$menu);
            Cache::forget('website.menu.tree.'.$menu);
        }
    }
}
