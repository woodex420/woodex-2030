<?php

declare(strict_types=1);

namespace App\Domain\Website\Actions;

use App\Domain\Website\Enums\BlockType;
use App\Domain\Website\Enums\PageStatus;
use App\Domain\Website\Enums\PageType;
use App\Domain\Website\Models\Page;
use Illuminate\Support\Str;

/**
 * A discipline named in a section points at its page (docs/10 §4).
 *
 * The old website listed its six disciplines again on almost every page, but one of them — Furniture — had no page
 * to point at and was written with an empty link. When the studio's own furniture page arrived, every one of those
 * lists still pointed nowhere, so the page was invisible: a link to nothing is not a link.
 *
 * This runs after every import: if an item in a services section names a discipline we have a page for, its link
 * is filled in. Nothing else is touched, and a name that matches no page is left exactly as the studio typed it.
 *
 * @return int how many links were filled in
 */
final class LinkEmptyServiceUrls
{
    public function __invoke(): int
    {
        /**
         * How to recognise a discipline when a section names it: by the words of the URL (a page at
         * `/furniture/` answers an item called "Furniture") and by its title.
         *
         * @var array<string, string>
         */
        $services = Page::query()
            ->where('status', PageStatus::Published->value)
            ->ofType(PageType::Service)
            ->get(['slug', 'title'])
            ->flatMap(fn (Page $page): array => [
                self::key((string) Str::of($page->slug)->trim('/')->afterLast('/')->replace('-', ' ')) => $page->slug,
                self::key((string) $page->title) => $page->slug,
            ])->all();

        $filled = 0;
        $touched = [];

        Page::query()->with('blocks')->get()->each(function (Page $page) use ($services, &$filled, &$touched): void {
            foreach ($page->blocks as $block) {
                if ($block->type !== BlockType::Services) {
                    continue;
                }

                $data = $block->data ?? [];
                $items = $data['items'] ?? [];

                foreach ($items as $index => $item) {
                    if (! is_array($item) || trim((string) ($item['url'] ?? '')) !== '') {
                        continue;
                    }

                    $url = $services[self::key((string) ($item['name'] ?? ''))] ?? null;

                    if ($url === null) {
                        continue;
                    }

                    $items[$index]['url'] = $url;
                    $filled++;
                }

                $data['items'] = $items;
                $block->data = $data;
                $block->save();
                $touched[$page->id] = true;
            }
        });

        foreach (array_keys($touched) as $id) {
            Page::query()->whereKey($id)->first()?->touch();
        }

        return $filled;
    }

    private static function key(string $name): string
    {
        return (string) Str::of($name)->lower()->squish();
    }
}
