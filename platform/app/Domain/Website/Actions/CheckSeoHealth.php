<?php

declare(strict_types=1);

namespace App\Domain\Website\Actions;

use App\Domain\Website\Models\Page;
use App\Domain\Website\Models\Redirect;

/**
 * The SEO agent's rule-based core (P8.5): a read-only weekly look at the website's search basics.
 * It reports; it never edits, never publishes, never guesses — every finding is a plain rule a
 * person can check. The owner decides what to do with the list.
 */
final class CheckSeoHealth
{
    /**
     * @return array{published: int, missing_description: list<string>, long_titles: list<string>, broken_redirects: list<string>}
     */
    public function handle(): array
    {
        $pages = Page::query()->published()->get();

        $missingDescription = $pages
            ->filter(fn (Page $page): bool => trim((string) ($page->seo['description'] ?? '')) === '' && trim((string) $page->excerpt) === '')
            ->map(fn (Page $page): string => $page->title)
            ->values()->all();

        $longTitles = $pages
            ->filter(fn (Page $page): bool => mb_strlen((string) ($page->seo['title'] ?? $page->title)) > 65)
            ->map(fn (Page $page): string => $page->title.' ('.mb_strlen((string) ($page->seo['title'] ?? $page->title)).' chars)')
            ->values()->all();

        $slugs = $pages->map(fn (Page $page): string => (string) $page->slug)->all();

        $brokenRedirects = Redirect::query()
            ->get()
            ->filter(function (Redirect $redirect) use ($slugs): bool {
                $target = trim((string) $redirect->to_path);

                // External targets are out of our hands; internal ones must land on a published page.
                if ($target === '' || str_starts_with($target, 'http://') || str_starts_with($target, 'https://')) {
                    return false;
                }

                return ! in_array('/'.ltrim($target, '/'), $slugs, true);
            })
            ->map(fn (Redirect $redirect): string => $redirect->from_path.' → '.$redirect->to_path)
            ->values()->all();

        return [
            'published' => $pages->count(),
            'missing_description' => $missingDescription,
            'long_titles' => $longTitles,
            'broken_redirects' => $brokenRedirects,
        ];
    }

    /**
     * The findings as the digest's plain lines.
     *
     * @param  array{published: int, missing_description: list<string>, long_titles: list<string>, broken_redirects: list<string>}  $health
     * @return list<string>
     */
    public function lines(array $health): array
    {
        $lines = [sprintf('🔍 %d published pages checked', (int) $health['published'])];

        $missing = $health['missing_description'];
        $lines[] = sprintf(
            '📝 %d page(s) missing a meta description%s',
            count($missing),
            $missing !== [] ? ': '.implode(', ', array_slice($missing, 0, 8)).(count($missing) > 8 ? ' …' : '') : ' — good',
        );

        $long = $health['long_titles'];
        $lines[] = sprintf('%d title(s) longer than 65 characters%s', count($long), $long !== [] ? ': '.implode(', ', array_slice($long, 0, 5)) : ' — good');

        $broken = $health['broken_redirects'];
        $lines[] = sprintf('↪️ %d redirect(s) pointing at no published page%s', count($broken), $broken !== [] ? ': '.implode(', ', array_slice($broken, 0, 5)) : ' — good');

        if ($missing === [] && $long === [] && $broken === []) {
            $lines[] = '✅ Everything looks healthy — nothing to fix this week.';
        }

        return $lines;
    }
}
