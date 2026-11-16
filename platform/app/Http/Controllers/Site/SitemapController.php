<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Domain\Website\Builder\BuilderFile;
use App\Domain\Website\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * sitemap.xml and robots.txt for the public website (docs/09 §11.1). Everything listed answers 200 — a sitemap
 * that points at a 404 or a redirect is worse for search than a short sitemap.
 *
 * Since Woodex OS 1 the site is the v1 files under public/, so the sitemap is built from **the files**, not from
 * the CMS rows. Built from the rows it listed 57 of the 143 pages and was blind to the 86 service, city, area and
 * project pages that carry the site's search traffic (20 Oct). Publishing writes the page file (Blog and
 * Portfolio, docs/audit/20), so every published row has one; a row without a file is a 404 and is not listed.
 */
final class SitemapController
{
    /** Bumped when the document changes shape, so a cached sitemap from an older build is never served. */
    private const string VERSION = 'files-1';

    public function sitemap(): Response
    {
        $pages = BuilderFile::pages();

        // Keyed on what actually changed: the host being served, the newest file, the number of pages, the
        // newest row. The URLs are canonical, so the document is the same on every host — the host stays in the
        // key so a copy built under one host can never be served for another by accident. A publish or a
        // builder save changes the key, so the sitemap is never stale.
        $version = implode('|', [
            self::VERSION,
            request()->getSchemeAndHttpHost(),
            (string) collect($pages)->max('mtime'),
            count($pages),
            (string) (Page::query()->max('updated_at') ?? 'none'),
        ]);

        $xml = Cache::remember('website.sitemap.'.md5($version), now()->addDay(), fn (): string => $this->build($pages));

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /**
     * One file serves both domains, so it answers for the one that is asking: the admin keeps everything out of
     * search, the public website asks to be crawled. There used to be a static public/robots.txt with "Disallow: /"
     * in it, which silently kept the whole new website out of Google (P-13).
     */
    public function robots(Request $request): Response
    {
        $adminDomain = config('woodex.admin.domain');
        $isAdmin = is_string($adminDomain) && $adminDomain !== ''
            && $request->getHost() === $adminDomain;

        $lines = $isAdmin
            ? ['User-agent: *', 'Disallow: /']
            : [
                'User-agent: *',
                'Allow: /',
                'Disallow: /'.trim((string) config('woodex.admin.path', 'admin'), '/'),
                'Disallow: /q/',
                'Disallow: /i/',
                'Disallow: /p/',
                'Disallow: /portal',
                '',
                'Sitemap: '.url('/sitemap.xml'),
            ];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * @param  list<array{path: string, url: string, title: string, mtime: int, size: int}>  $pages
     */
    private function build(array $pages): string
    {
        $entries = [];

        foreach ($pages as $page) {
            $html = (string) @file_get_contents(public_path($page['path']));

            if (self::noindex($html)) {
                continue;
            }

            // `BuilderFile` works in paths (`/about/`); a sitemap needs the absolute URL.
            $entries[self::absolute($page['url'])] = $this->entry(
                self::absolute($page['url']),
                gmdate('Y-m-d', $page['mtime']),
                $page['url'],
                $page['url'] === '/',
            );
        }

        // Only files are listed: a published row without a file answers 404 (the renderer was retired), so
        // advertising it would send Google to a dead page. Rows still bump the cache key above, so a publish is
        // never served stale.

        // The home page first, then alphabetical — stable output, so a diff is a real change.
        uksort($entries, static fn (string $a, string $b): int => [$entries[$a]['home'] ? 0 : 1, $a] <=> [$entries[$b]['home'] ? 0 : 1, $b]);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($entries as $entry) {
            $xml .= '  <url>'
                .'<loc>'.e($entry['loc']).'</loc>'
                .'<lastmod>'.$entry['lastmod'].'</lastmod>'
                .'<changefreq>'.$entry['changefreq'].'</changefreq>'
                .'<priority>'.$entry['priority'].'</priority>'
                .'</url>'."\n";
        }

        return $xml.'</urlset>'."\n";
    }

    /**
     * One URL's line: the home page leads, a section page outranks an article, and the long-form pages
     * (/insights/…, /projects/…) change less often than the service and city pages around them.
     *
     * The `home` flag rides along so the ordering does not depend on what `url('/')` looks like in the
     * environment the sitemap is built in (a console with no host returns `/`).
     *
     * @return array{loc: string, lastmod: string, changefreq: string, priority: string, home: bool}
     */
    private function entry(string $loc, string $lastmod, string $path, bool $home): array
    {
        $depth = max(0, substr_count(trim($path, '/'), '/'));

        return [
            'loc' => $loc,
            'lastmod' => $lastmod,
            'changefreq' => str_starts_with($path, '/insights/') || str_starts_with($path, '/projects/') ? 'monthly' : 'weekly',
            'priority' => $home ? '1.0' : ($depth === 0 ? '0.8' : ($depth === 1 ? '0.6' : '0.5')),
            'home' => $home,
        ];
    }

    /**
     * The absolute URL of a path. A sitemap is read by crawlers, so it must never hand out a bare `/about/`:
     * `url()` answers with the request's host, and falls back to the configured site address in a context that
     * has no host (a console command, the sandbox's PHP runtime).
     */
    private static function absolute(string $path): string
    {
        // A sitemap lists canonical URLs, so it always names the public site — never the host that happened to
        // ask. (Built from the request, a fetch through the admin domain put admin.woodex.com.pk in the sitemap,
        // and an environment that reports no host produced a relative <loc>, which is not valid.)
        $base = rtrim((string) config('woodex.public_site_url'), '/');

        if (! str_starts_with($base, 'http')) {
            $base = rtrim((string) (url('/') === '/' ? config('app.url') : url('/')), '/');
        }

        return $base.'/'.ltrim($path, '/');
    }

    /** A page says it itself: `<meta name="robots" content="noindex">` in the file it is. */
    private static function noindex(string $html): bool
    {
        return (bool) preg_match('~<meta[^>]+name=["\']robots["\'][^>]+content=["\'][^"\']*noindex~i', $html);
    }
}
