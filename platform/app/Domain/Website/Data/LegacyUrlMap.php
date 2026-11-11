<?php

declare(strict_types=1);

namespace App\Domain\Website\Data;

use App\Domain\Website\Enums\PageType;

/**
 * Every URL of the previous website and where it lands now (docs/09 §3, D-68).
 *
 * The map is the single source of truth for three things: the importer (`woodex:import-legacy`, which turns the old
 * HTML into pages), the 301 redirects (seeded by a migration, edited in the admin afterwards) and the parity test
 * that checks all 142 URLs have a file. It was generated from the old site's 142 HTML files. `/furniture/` was in
 * the map as the one page the old site did not have; the owner deleted the page on 21 Oct 2026, so it is out of the
 * map too — the contract now matches the site.
 */
final class LegacyUrlMap
{
    /** @var array{pages: array<string, array{type: string, source: string|null, seed_from?: string}>, redirects: array<string, string>}|null */
    private static ?array $map = null;

    /**
     * Slug => page type and the old HTML file, relative to the old site root (null for a page that is new).
     *
     * @return array<string, array{type: string, source: string|null}>
     */
    public static function pages(): array
    {
        return self::load()['pages'];
    }

    /**
     * Old slug => the slug it 301s to.
     *
     * @return array<string, string>
     */
    public static function redirects(): array
    {
        return self::load()['redirects'];
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_keys(self::pages());
    }

    /** Every URL the old site answered: the pages we keep and the ones that redirect. */
    public static function count(): int
    {
        return count(self::pages()) + count(self::redirects());
    }

    public static function typeFor(string $slug): PageType
    {
        $type = self::pages()[$slug]['type'] ?? null;

        return PageType::tryFrom((string) $type) ?? PageType::Listing;
    }

    /** The old HTML file for a slug, or null when the page has no source (a new page, or a redirect). */
    public static function sourceFor(string $slug): ?string
    {
        return self::pages()[$slug]['source'] ?? null;
    }

    public static function isRedirect(string $slug): bool
    {
        return isset(self::redirects()[$slug]);
    }

    /** Where a redirected URL now points, or null when the URL stays a page. */
    public static function targetFor(string $slug): ?string
    {
        return self::redirects()[$slug] ?? null;
    }

    public static function forget(): void
    {
        self::$map = null;
    }

    /**
     * @return array{pages: array<string, array{type: string, source: string|null, seed_from?: string}>, redirects: array<string, string>}
     */
    private static function load(): array
    {
        if (self::$map === null) {
            /** @var array{pages: array<string, array{type: string, source: string|null, seed_from?: string}>, redirects: array<string, string>} $map */
            $map = require base_path('database/data/legacy-website.php');
            self::$map = $map;
        }

        return self::$map;
    }
}
