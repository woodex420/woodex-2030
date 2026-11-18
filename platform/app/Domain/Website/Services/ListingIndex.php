<?php

declare(strict_types=1);

namespace App\Domain\Website\Services;

use App\Domain\Website\Enums\PageStatus;
use App\Domain\Website\Enums\PageType;
use App\Domain\Website\Models\Page;
use App\Domain\Website\Models\PageBlock;

/**
 * The link between a piece of content and the index page that lists it.
 *
 * `/insights/` and `/projects/` are ordinary pages whose card list is a hand-curated `services` block —
 * Frontend v1 is final, so the index is not generated from the database. This service keeps that list
 * honest from the content screens: it says whether a post or study is on its index, adds the card, and
 * takes it off again, without anyone opening the index page and retyping one.
 *
 * A card is matched by URL, and only published pages may be added: a card on a live page must never
 * lead to a draft.
 */
final class ListingIndex
{
    /** The block the index pages use for their cards. */
    private const string BLOCK = 'services';

    /** The index page each content type belongs to when the page carries no parent. */
    private const array INDEX_SLUGS = [
        'insight' => '/insights/',
        'project' => '/projects/',
    ];

    /** The index page for a piece of content: its parent, or the conventional page for its type. */
    public static function listingFor(Page $page): ?Page
    {
        if ($page->parent_id !== null) {
            $parent = Page::query()->find($page->parent_id);

            if ($parent !== null) {
                return $parent;
            }
        }

        $slug = self::INDEX_SLUGS[$page->type->value] ?? null;

        return $slug === null ? null : Page::query()->where('slug', $slug)->first();
    }

    /**
     * The URLs the index already links to, normalised so `/projects/x` and `/projects/x/` match.
     *
     * @return list<string>
     */
    public static function urls(?Page $listing): array
    {
        if ($listing === null) {
            return [];
        }

        $block = self::block($listing);

        if ($block === null) {
            return [];
        }

        return array_values(array_map(
            fn (array $item): string => self::normalise((string) ($item['url'] ?? '')),
            self::items($block),
        ));
    }

    public static function isListed(Page $page): bool
    {
        return in_array(self::normalise($page->slug), self::urls(self::listingFor($page)), true);
    }

    /** Adds the page's card to the end of the index. Returns false when there is no index to add it to. */
    public static function add(Page $page): bool
    {
        if ($page->status !== PageStatus::Published) {
            return false;
        }

        $listing = self::listingFor($page);
        $block = $listing === null ? null : self::block($listing);

        if ($listing === null || $block === null) {
            return false;
        }

        $url = self::normalise($page->slug);
        $items = self::items($block);

        foreach ($items as $item) {
            if (self::normalise((string) ($item['url'] ?? '')) === $url) {
                return true;
            }
        }

        $items[] = [
            'number' => (string) (count($items) + 1),
            'name' => $page->title,
            'copy' => self::label($page),
            'url' => $url,
        ];

        self::write($listing, $block, $items);

        return true;
    }

    /** Takes the page's card off the index. Returns false when the page was not on it. */
    public static function remove(Page $page): bool
    {
        $listing = self::listingFor($page);
        $block = $listing === null ? null : self::block($listing);

        if ($listing === null || $block === null) {
            return false;
        }

        $url = self::normalise($page->slug);
        $items = self::items($block);
        $kept = array_values(array_filter(
            $items,
            fn (array $item): bool => self::normalise((string) ($item['url'] ?? '')) !== $url,
        ));

        if (count($kept) === count($items)) {
            return false;
        }

        self::write($listing, $block, $kept);

        return true;
    }

    private static function block(Page $listing): ?PageBlock
    {
        return $listing->blocks()
            ->where('type', self::BLOCK)
            ->orderBy('position')
            ->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function items(PageBlock $block): array
    {
        $items = $block->data['items'] ?? [];

        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter($items, 'is_array'));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private static function write(Page $listing, PageBlock $block, array $items): void
    {
        $data = $block->data ?? [];

        // The number is the card's rank, so removing one card never leaves a gap in the list.
        $data['items'] = array_map(
            fn (array $item, int $position): array => [...$item, 'number' => (string) ($position + 1)],
            $items,
            array_keys($items),
        );

        $block->update(['data' => $data]);

        PageCache::invalidate($listing);
    }

    /** What the card calls the content, matching how the index pages already word their cards. */
    private static function label(Page $page): string
    {
        return match ($page->type) {
            PageType::Project => 'Study',
            PageType::Insight => 'Guide',
            default => '',
        };
    }

    private static function normalise(string $url): string
    {
        $url = trim($url);

        // An outside link is not this page's path; compare it as written.
        if ($url === '' || ! str_starts_with($url, '/')) {
            return $url;
        }

        return '/'.trim($url, '/').'/';
    }
}
