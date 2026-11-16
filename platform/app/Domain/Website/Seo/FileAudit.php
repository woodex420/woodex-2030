<?php

declare(strict_types=1);

namespace App\Domain\Website\Seo;

use App\Domain\Website\Builder\BuilderFile;
use App\Domain\Website\Models\Page;
use App\Domain\Website\Models\Redirect;

/**
 * The SEO audit, read from the site itself (Woodex OS 1, docs/audit/16.19 step 6).
 *
 * `SeoAudit` read the `pages` table: 56 rows out of the 143 pages the web server serves, so the owner was
 * told "everything checks out" about a fifth of the site. A page is a file, so the audit reads the files —
 * the same list the builder edits — and finds what a person can check by opening them: a title, a
 * description, one H1, alt text, enough words, and every internal link answering.
 *
 * No model, no key, no guessing: each finding names the file and the rule.
 */
final class FileAudit
{
    /**
     * What each rule is called on the screen: the code is for the machine, the label is for the person
     * reading the worklist.
     *
     * @var array<string, string>
     */
    public const array LABELS = [
        'missing_title' => 'No title',
        'long_title' => 'Title cut off',
        'short_title' => 'Title too thin',
        'duplicate_title' => 'Repeated title',
        'missing_description' => 'No description',
        'long_description' => 'Description cut off',
        'duplicate_description' => 'Repeated description',
        'missing_h1' => 'No H1',
        'multiple_h1' => 'More than one H1',
        'missing_alt' => 'Images without alt text',
        'thin_content' => 'Thin content',
        'noindex' => 'Kept out of search',
        'broken_links' => 'Links that lead nowhere',
        'orphan_pages' => 'Pages nothing links to',
        'broken_redirects' => 'Redirects to nowhere',
    ];

    /** First segments that belong to the app, never to a page (`SiteRouter`). */
    private const array RESERVED = [
        'admin', 'api', 'assets', 'build', 'builder', 'livewire', 'media', 'storage', 'theme', 'vendor',
        'webhooks', 'install', 'up', 'q', 'i', 'p', 'portal',
    ];

    /**
     * @return array{
     *     entries: list<array{path: string, url: string, title: string, description: string, words: int, h1: int, issues: list<array{code: string, message: string}>}>,
     *     pages: list<array{path: string, url: string, title: string, description: string, words: int, h1: int, issues: list<array{code: string, message: string}>}>,
     *     site: list<array{code: string, message: string}>,
     *     counts: array{files: int, flagged: int, issues: int, images_without_alt: int, broken_links: int, orphans: int, redirects_astray: int}
     * }
     */
    public function handle(): array
    {
        $files = BuilderFile::pages();

        $read = [];
        $existing = ['/'];

        foreach ($files as $file) {
            $html = (string) @file_get_contents(public_path($file['path']));
            $existing[] = $file['url'];

            $read[] = [
                'path' => $file['path'],
                'url' => $file['url'],
                'title' => self::title($html),
                'description' => self::meta($html, 'description'),
                'h1' => preg_match_all('~<h1\b~i', $html),
                'words' => self::words($html),
                'images_without_alt' => self::imagesWithoutAlt($html),
                'links' => self::links($html),
                'noindex' => self::noindex($html),
            ];
        }

        // A CMS page with no file is still a URL: `SiteRouter` answers it. Links to it are not broken.
        Page::query()->published()->get(['slug'])
            ->each(function (Page $page) use (&$existing): void {
                $existing[] = (string) $page->slug;
            });

        $existing = array_values(array_unique($existing));

        $titles = self::duplicates($read, 'title');
        $descriptions = self::duplicates($read, 'description');

        $pages = [];
        $entries = [];

        foreach ($read as $page) {
            $issues = [];

            $title = $page['title'];
            $description = $page['description'];

            if ($title === '') {
                $issues[] = ['code' => 'missing_title', 'message' => 'No <title> — the browser tab and the search result have nothing to say.'];
            } elseif (mb_strlen($title) > 65) {
                $issues[] = ['code' => 'long_title', 'message' => sprintf('Title is %d characters — search engines cut around 65.', mb_strlen($title))];
            } elseif (mb_strlen($title) < 20) {
                $issues[] = ['code' => 'short_title', 'message' => sprintf('Title is only %d characters — room for the studio and the subject.', mb_strlen($title))];
            }

            if ($title !== '' && isset($titles[$title])) {
                $others = array_values(array_diff($titles[$title], [$page['url']]));

                if ($others !== []) {
                    $issues[] = ['code' => 'duplicate_title', 'message' => 'The same title as: '.self::sample($others)];
                }
            }

            if ($description === '') {
                $issues[] = ['code' => 'missing_description', 'message' => 'No meta description — search engines show whatever they find.'];
            } elseif (mb_strlen($description) > 165) {
                $issues[] = ['code' => 'long_description', 'message' => sprintf('Description is %d characters — search engines cut around 165.', mb_strlen($description))];
            }

            if ($description !== '' && isset($descriptions[$description])) {
                $others = array_values(array_diff($descriptions[$description], [$page['url']]));

                if ($others !== []) {
                    $issues[] = ['code' => 'duplicate_description', 'message' => 'The same description as: '.self::sample($others)];
                }
            }

            if ($page['h1'] === 0) {
                $issues[] = ['code' => 'missing_h1', 'message' => 'No H1 — the page has no headline for a search engine to anchor on.'];
            } elseif ($page['h1'] > 1) {
                $issues[] = ['code' => 'multiple_h1', 'message' => sprintf('%d H1 headings — one headline per page, the rest are H2.', $page['h1'])];
            }

            if ($page['images_without_alt'] > 0) {
                $issues[] = ['code' => 'missing_alt', 'message' => sprintf('%d image(s) without alt text — screen readers hear nothing, and Google neither.', $page['images_without_alt'])];
            }

            if ($page['words'] < 120 && $page['url'] !== '/') {
                $issues[] = ['code' => 'thin_content', 'message' => sprintf('Only about %d words of body text — search engines read thin pages as thin answers.', $page['words'])];
            }

            if ($page['noindex']) {
                $issues[] = ['code' => 'noindex', 'message' => 'The page asks search engines not to index it.'];
            }

            $entry = [
                'path' => $page['path'],
                'url' => $page['url'],
                'title' => $title,
                'description' => $description,
                'words' => $page['words'],
                'h1' => $page['h1'],
                'issues' => $issues,
            ];

            $entries[] = $entry;

            if ($issues !== []) {
                $pages[] = $entry;
            }
        }

        usort($pages, static fn (array $a, array $b): int => count($b['issues']) <=> count($a['issues']));

        $broken = self::brokenLinks($read, $existing);
        $orphans = self::orphans($read);
        $astray = Redirect::query()->get()->filter(fn (Redirect $redirect): bool => ! self::answers((string) $redirect->to_path, $existing));

        $site = [];

        if ($broken !== []) {
            $site[] = [
                'code' => 'broken_links',
                'message' => sprintf('%d internal link(s) lead nowhere: %s', array_sum(array_map('count', $broken)), self::sample(array_keys($broken))),
            ];
        }

        if ($orphans !== []) {
            $site[] = [
                'code' => 'orphan_pages',
                'message' => sprintf('Nothing links to %d page(s): %s', count($orphans), self::sample($orphans)),
            ];
        }

        if ($astray->isNotEmpty()) {
            $site[] = [
                'code' => 'broken_redirects',
                'message' => sprintf('%d redirect(s) point at a page that does not exist: %s', $astray->count(), self::sample($astray->pluck('from_path')->all())),
            ];
        }

        return [
            'entries' => $entries,
            'pages' => $pages,
            'site' => $site,
            'counts' => [
                'files' => count($files),
                'flagged' => count($pages),
                'issues' => array_sum(array_map(static fn (array $page): int => count($page['issues']), $pages)),
                'images_without_alt' => array_sum(array_column($read, 'images_without_alt')),
                'broken_links' => array_sum(array_map('count', $broken)),
                'orphans' => count($orphans),
                'redirects_astray' => $astray->count(),
            ],
        ];
    }

    private static function title(string $html): string
    {
        return preg_match('~<title[^>]*>(.*?)</title>~is', $html, $match) === 1
            ? trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5))
            : '';
    }

    /** A `<meta name="…" content="…">`, in either attribute order. */
    private static function meta(string $html, string $name): string
    {
        $quoted = preg_quote($name, '~');

        $patterns = [
            '~<meta[^>]+name=["\']'.$quoted.'["\'][^>]*content=["\']([^"\']*)["\']~i',
            '~<meta[^>]+content=["\']([^"\']*)["\'][^>]*name=["\']'.$quoted.'["\']~i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $match) === 1) {
                return trim(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5));
            }
        }

        return '';
    }

    private static function noindex(string $html): bool
    {
        return str_contains(mb_strtolower(self::meta($html, 'robots')), 'noindex');
    }

    /** Body text only: no scripts, no styles, no tags. */
    private static function words(string $html): int
    {
        $body = preg_replace('~<(script|style|noscript)\b[^>]*>.*?</\1>~is', ' ', $html) ?? $html;
        $text = trim(preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5)) ?? '');

        return $text === '' ? 0 : count(preg_split('~\s+~u', $text) ?: []);
    }

    /**
     * Content images with nothing for a screen reader: no `alt`, or an empty one. Scoped to `<main>` — the
     * brand mark in the header and the footer is decorative and carries `alt=""` on purpose; a hero image in
     * the middle of the page does not.
     */
    private static function imagesWithoutAlt(string $html): int
    {
        if (preg_match('~<main\b[^>]*>(.*?)</main>~is', $html, $main) === 1) {
            $html = $main[1];
        }

        preg_match_all('~<img\b[^>]*>~i', $html, $images);

        $count = 0;

        foreach ($images[0] as $image) {
            if (preg_match('~\balt=["\']([^"\']*)["\']~i', $image, $alt) !== 1 || trim($alt[1]) === '') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Internal links in the page, as paths: `/about/`. External URLs, anchors and mail links are not links
     * the site can break.
     *
     * @return list<string>
     */
    private static function links(string $html): array
    {
        preg_match_all('~href=["\']([^"\']+)["\']~i', $html, $matches);

        $links = [];

        foreach ($matches[1] as $href) {
            $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5));

            if ($href === '' || str_starts_with($href, '#') || preg_match('~^(mailto|tel|javascript|sms|whatsapp):~i', $href) === 1) {
                continue;
            }

            if (str_starts_with($href, '//') || preg_match('~^https?://~i', $href) === 1) {
                continue;
            }

            if (! str_starts_with($href, '/')) {
                continue;
            }

            $path = explode('#', explode('?', $href)[0])[0];

            if ($path !== '') {
                $links[] = self::canonicalPath($path);
            }
        }

        return array_values(array_unique($links));
    }

    /** `/about` and `/about/` are the same page to the web server. */
    private static function canonicalPath(string $path): string
    {
        $path = '/'.ltrim($path, '/');

        if ($path === '/' || str_contains(basename($path), '.')) {
            return $path;
        }

        return str_ends_with($path, '/') ? $path : $path.'/';
    }

    /**
     * @param  list<array{path: string, url: string, title: string, description: string, h1: int, words: int, images_without_alt: int, links: list<string>, noindex: bool}>  $read
     * @param  list<string>  $existing
     * @return array<string, list<string>> linked path => pages linking to it
     */
    private static function brokenLinks(array $read, array $existing): array
    {
        $known = array_flip(array_map(self::canonicalPath(...), $existing));
        $broken = [];

        foreach ($read as $page) {
            foreach ($page['links'] as $link) {
                if (isset($known[$link]) || self::isAsset($link) || self::fileExists($link)) {
                    continue;
                }

                $broken[$link][] = $page['url'];
            }
        }

        ksort($broken);

        return $broken;
    }

    /** The app's own URLs (media, theme, assets, forms API) are not pages to check. */
    private static function isAsset(string $path): bool
    {
        $first = explode('/', trim($path, '/'))[0] ?? '';

        return in_array(strtolower($first), self::RESERVED, true);
    }

    /** A real file under public/, e.g. `/assets/img/x.webp`. */
    private static function fileExists(string $path): bool
    {
        return is_file(public_path(ltrim($path, '/')));
    }

    /**
     * @param  list<array{url: string, links: list<string>}>  $read
     * @return list<string>
     */
    private static function orphans(array $read): array
    {
        $linked = [];

        foreach ($read as $page) {
            foreach ($page['links'] as $link) {
                $linked[$link] = true;
            }
        }

        $orphans = [];

        foreach ($read as $page) {
            if ($page['url'] === '/' || isset($linked[$page['url']])) {
                continue;
            }

            $orphans[] = $page['url'];
        }

        sort($orphans);

        return $orphans;
    }

    /** @param  list<string>  $existing */
    private static function answers(string $path, array $existing): bool
    {
        $path = trim($path);

        if ($path === '' || preg_match('~^https?://~i', $path) === 1) {
            return true;
        }

        $canonical = self::canonicalPath($path);

        return in_array($canonical, array_map(self::canonicalPath(...), $existing), true) || self::fileExists($canonical);
    }

    /**
     * The same title (or description) on more than one page, as `value => other page URLs`.
     *
     * @param  list<array{url: string, title: string, description: string}>  $read
     * @return array<string, list<string>>
     */
    private static function duplicates(array $read, string $key): array
    {
        $seen = [];

        foreach ($read as $page) {
            $value = $page[$key];

            if ($value === '') {
                continue;
            }

            $seen[$value][] = $page['url'];
        }

        return array_filter($seen, static fn (array $urls): bool => count($urls) > 1);
    }

    /** @param  list<string>  $values */
    private static function sample(array $values): string
    {
        return implode(', ', array_slice($values, 0, 5)).(count($values) > 5 ? ' …' : '');
    }
}
