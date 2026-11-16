<?php

declare(strict_types=1);

namespace App\Domain\Website\Import;

use App\Domain\Website\Data\LegacyUrlMap;
use App\Domain\Website\Enums\BlockType;
use App\Domain\Website\Models\Page;
use App\Domain\Website\Models\PageBlock;

/**
 * Points the links of imported pages at pages that exist (docs/09 §13 step 6).
 *
 * The old pages link to each other freely, and 54 of those URLs are now redirects: a "Related" section is turned
 * into the list of pages it links to (when those pages exist), and every other link is rewritten to the page that
 * now answers it. Run after the import, when every page has a record.
 */
final class LinkImportedPages
{
    public function __invoke(): int
    {
        $slugs = $this->pageIds();
        $changed = 0;

        PageBlock::query()->with('page')->whereIn('type', [BlockType::Related->value, BlockType::Services->value])
            ->orderBy('id')
            ->chunkById(200, function ($blocks) use ($slugs, &$changed): void {
                /** @var iterable<PageBlock> $blocks */
                foreach ($blocks as $block) {
                    $data = is_array($block->data) ? $block->data : [];
                    $before = $data;
                    $own = $block->page?->slug; // a page does not link to itself

                    if (($block->type === BlockType::Related) && isset($data['urls']) && is_array($data['urls'])) {
                        $ids = [];

                        foreach ($data['urls'] as $url) {
                            $target = $this->slugOf((string) $url);
                            $id = $slugs[$target] ?? null;

                            if ($id !== null && $target !== $own && ! in_array($id, $ids, true)) {
                                $ids[] = $id;
                            }
                        }

                        $data['page_ids'] = $ids;
                        unset($data['urls']);
                    }

                    foreach (['items', 'stages'] as $key) {
                        if (! isset($data[$key]) || ! is_array($data[$key])) {
                            continue;
                        }

                        $data[$key] = array_map(function (mixed $item) use ($own): mixed {
                            if (! is_array($item) || ! isset($item['url']) || ! is_string($item['url']) || $item['url'] === '') {
                                return $item;
                            }

                            $target = $this->slugOf($item['url']);
                            $item['url'] = $target === $own ? '' : $target;

                            return $item;
                        }, $data[$key]);
                    }

                    if ($data !== $before) {
                        $block->forceFill(['data' => $data])->save();
                        $changed++;
                    }
                }
            });

        return $changed;
    }

    /** @return array<string, int> slug => page id */
    private function pageIds(): array
    {
        return Page::query()->pluck('id', 'slug')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /** A link as the site writes it: with its trailing slash, and pointing at the page that answers it now. */
    private function slugOf(string $url): string
    {
        $path = '/'.trim((string) parse_url($url, PHP_URL_PATH), '/').'/';
        $path = $path === '//' ? '/' : $path;

        // An old URL that is now a redirect points at the page that answers it.
        return LegacyUrlMap::targetFor($path) ?? $path;
    }
}
