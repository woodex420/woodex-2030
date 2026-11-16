<?php

declare(strict_types=1);

namespace App\Domain\Website\Services;

use App\Domain\Website\Enums\BlockType;
use App\Domain\Website\Models\Media;
use App\Domain\Website\Models\Page;
use Illuminate\Support\Str;

/**
 * The gate a page must pass before it can go live (docs/09 §2.2): a thin page published today is a thin page in
 * search results for a year. Drafts can be saved at any time; only publishing checks this list.
 */
final class PageValidator
{
    /**
     * @param  array{title: string, slug: string, excerpt: ?string, seo: array<string, mixed>, blocks: list<array<string, mixed>>}  $input
     * @return list<string>
     */
    public static function issues(array $input, ?Page $page = null): array
    {
        $issues = [];
        $title = trim((string) ($input['title'] ?? ''));
        $slug = trim((string) ($input['slug'] ?? ''));
        $excerpt = trim((string) ($input['excerpt'] ?? ''));
        $seo = $input['seo'] ?? [];
        $blocks = array_values(array_filter(
            (array) ($input['blocks'] ?? []),
            fn (array $block): bool => (bool) ($block['visible'] ?? true),
        ));

        if ($title === '') {
            $issues[] = 'The page needs a title.';
        }

        if ($slug === '' || ! str_starts_with($slug, '/') || ! str_ends_with($slug, '/')) {
            $issues[] = 'The URL must start and end with a slash, for example /interior-design/.';
        }

        if (Str::length($title) > 60) {
            $issues[] = 'The title is '.Str::length($title).' characters: keep it under 60 so it is not cut off in search results.';
        }

        $seoTitle = trim((string) ($seo['title'] ?? ''));
        $seoDescription = trim((string) ($seo['description'] ?? ''));

        if ($seoTitle === '') {
            $issues[] = 'Add an SEO title (the blue link in search results).';
        } elseif (Str::length($seoTitle) > 60) {
            $issues[] = 'The SEO title is '.Str::length($seoTitle).' characters: keep it under 60.';
        }

        if (Str::length($seoDescription) < 120) {
            $issues[] = 'Write a meta description of at least 120 characters (it is '.Str::length($seoDescription).' now).';
        } elseif (Str::length($seoDescription) > 165) {
            $issues[] = 'The meta description is '.Str::length($seoDescription).' characters: keep it under 165.';
        }

        if ($excerpt === '') {
            $issues[] = 'Add a summary: it appears in the search result, in menus and in links to this page.';
        }

        if (count($blocks) < 2) {
            $issues[] = 'Add at least two sections: a page with one section reads as unfinished.';
        }

        // A slider opens just as well as a hero (the v26 home is built that way), so the gate asks
        // for an opening section, not for one particular kind of it.
        $openings = [BlockType::Hero->value, BlockType::Slider->value, BlockType::Intro->value, BlockType::Text->value];

        if (! collect($blocks)->contains(fn (array $block): bool => in_array($block['type'] ?? '', $openings, true))) {
            $issues[] = 'Add a hero, a slider or an intro section: the page needs an opening someone can read.';
        }

        foreach (self::missingAltText($blocks) as $missing) {
            $issues[] = $missing;
        }

        if ($page !== null) {
            $taken = Page::query()->where('slug', $slug)->whereKeyNot($page->id)->exists();

            if ($taken) {
                $issues[] = 'Another page already uses the URL '.$slug.'.';
            }
        }

        return $issues;
    }

    /**
     * Every image a page shows must have alt text (D-66): screen readers read it aloud, and it is the only
     * description of the image that search engines and AI assistants ever see.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<string>
     */
    public static function missingAltText(array $blocks): array
    {
        $ids = [];

        foreach ($blocks as $block) {
            foreach ((array) ($block['data'] ?? []) as $key => $value) {
                if ((string) $key === 'image_id' && is_numeric($value)) {
                    $ids[] = (int) $value;
                }

                if (in_array((string) $key, ['images', 'logos'], true) && is_array($value)) {
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            // A caption in the block counts: it is the visible description of that image.
                            if (isset($item['id']) && is_numeric($item['id']) && trim((string) ($item['alt'] ?? '')) === '') {
                                $ids[] = (int) $item['id'];
                            }
                        }
                    }
                }
            }
        }

        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids === []) {
            return [];
        }

        return Media::query()->whereIn('id', $ids)->get()
            ->filter(fn (Media $media): bool => trim($media->alt) === '')
            ->map(fn (Media $media): string => 'Alt text is missing for the image '.basename($media->path).'.')
            ->values()
            ->all();
    }
}
