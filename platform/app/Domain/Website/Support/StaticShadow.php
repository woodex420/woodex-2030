<?php

declare(strict_types=1);

namespace App\Domain\Website\Support;

/**
 * Phase 2 interim for audit finding W-01: the exported v1 pages are real files in public/, and the web
 * server answers them before Laravel sees the request. A CMS page can therefore be published to the
 * same URL and change nothing a visitor sees.
 *
 * The builder says so plainly instead of pretending the publish worked. Once a group is converted
 * (docs/13) its files are removed and this returns false for it.
 */
final class StaticShadow
{
    public static function covers(?string $slug): bool
    {
        if ($slug === null || trim($slug) === '') {
            return false;
        }

        $path = trim($slug, '/');

        return is_file($path === '' ? public_path('index.html') : public_path($path.'/index.html'));
    }

    /** The file that currently answers the URL, for the message in the editor. */
    public static function file(?string $slug): ?string
    {
        if (! self::covers($slug)) {
            return null;
        }

        $path = trim((string) $slug, '/');

        return $path === '' ? 'index.html' : $path.'/index.html';
    }
}
