<?php

declare(strict_types=1);

namespace App\Domain\Website\Speed;

use App\Domain\Website\Models\Media;
use App\Domain\Website\Models\Page;
use App\Domain\Website\Services\PageCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The speed dashboard's keyless half (V2-3 F9, docs/14 §5a): what the studio can see without asking
 * anyone's permission — every published page's image count, image weight and formats, the weight of
 * the cached HTML itself, and whether the full-page cache is on. File sizes come straight from the
 * disk; images whose file is missing count but weigh nothing (the SEO agent reports those as
 * missing — here they are simply not double-counted).
 */
final class InternalSpeedAudit
{
    private const int HEAVY_BYTES = 300 * 1024;

    private const array MODERN_FORMATS = ['webp', 'avif', 'svg'];

    /**
     * @return array{
     *     pages: list<array{id: int, title: string, url: string, images: int, bytes: int, heavy: list<string>, legacy: list<string>, cached_kb: ?float}>,
     *     totals: array{pages: int, images: int, bytes: int, heavy: int, legacy: int},
     *     cache_on: bool
     * }
     */
    public function handle(): array
    {
        $cacheOn = (bool) config('website.cache', true);
        $pages = [];
        $totalImages = 0;
        $totalBytes = 0;
        $totalHeavy = 0;
        $totalLegacy = 0;

        foreach (Page::query()->published()->with('blocks')->orderBy('slug')->get() as $page) {
            $media = Media::query()->findMany($this->imageIds($page));

            $bytes = 0;
            $heavy = [];
            $legacy = [];

            foreach ($media as $image) {
                $size = $this->bytes($image);
                $bytes += $size;

                if ($size > self::HEAVY_BYTES) {
                    $heavy[] = basename((string) $image->path);
                }

                if (! in_array(strtolower((string) pathinfo((string) $image->path, PATHINFO_EXTENSION)), self::MODERN_FORMATS, true)) {
                    $legacy[] = basename((string) $image->path);
                }
            }

            $totalImages += $media->count();
            $totalBytes += $bytes;
            $totalHeavy += count($heavy);
            $totalLegacy += count($legacy);

            $pages[] = [
                'id' => $page->id,
                'title' => $page->title,
                'url' => $page->path(),
                'images' => $media->count(),
                'bytes' => $bytes,
                'heavy' => $heavy,
                'legacy' => $legacy,
                'cached_kb' => $this->cachedKb($page),
            ];
        }

        usort($pages, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        return [
            'pages' => $pages,
            'totals' => ['pages' => count($pages), 'images' => $totalImages, 'bytes' => $totalBytes, 'heavy' => $totalHeavy, 'legacy' => $totalLegacy],
            'cache_on' => $cacheOn,
        ];
    }

    /**
     * The media ids a page shows: its featured image, every image field, and every image inside a
     * repeater row (gallery, clients, team…) — the same walk the renderer's media map does.
     *
     * @return list<int>
     */
    private function imageIds(Page $page): array
    {
        $ids = $page->featured_image_id !== null ? [(int) $page->featured_image_id] : [];

        foreach ($page->blocks as $block) {
            foreach ((array) ($block->data ?? []) as $key => $value) {
                if (str_ends_with((string) $key, '_id') && is_numeric($value)) {
                    $ids[] = (int) $value;
                }

                if (is_array($value)) {
                    foreach ($value as $item) {
                        if (is_array($item) && isset($item['id']) && is_numeric($item['id'])) {
                            $ids[] = (int) $item['id'];
                        }
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function bytes(Media $image): int
    {
        try {
            return (int) Storage::disk($image->disk)->size((string) $image->path);
        } catch (Throwable) {
            return 0; // the file is gone — the SEO agent's health sweep reports it, not the scales
        }
    }

    /**
     * The cached HTML's weight in kilobytes, when the page has been rendered for visitors already.
     */
    private function cachedKb(Page $page): ?float
    {
        $html = Cache::get(PageCache::key($page));

        return is_string($html) ? round(strlen($html) / 1024, 1) : null;
    }
}
