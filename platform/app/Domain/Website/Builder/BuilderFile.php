<?php

declare(strict_types=1);

namespace App\Domain\Website\Builder;

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

/**
 * The page the builder edits is an HTML file on disk (Woodex OS 1, docs/audit/16.19).
 *
 * The public site is the v1 frontend: every address is a real `…/index.html` under `public/`, and the web
 * server answers the file before the application is asked. So the builder edits those files directly — the way
 * the v2.1 admin did — instead of a database row that a file shadows. One page, one file, nothing to reconcile.
 *
 * Everything dangerous is kept from the database-backed builder: an explicit path allow-list, a conflict check
 * against the file's mtime, the script guard, and a snapshot of the previous HTML before every write, so any
 * save can be undone.
 */
final class BuilderFile
{
    /** Directories under public/ that are never pages: the app's own URLs and the site's assets. */
    private const array RESERVED = [
        'admin', 'assets', 'build', 'builder', 'vendor', 'storage', 'livewire', 'api', 'webhooks', 'media', 'img',
    ];

    /** How many previous versions of one page are kept. */
    private const int KEEP_VERSIONS = 40;

    public static function root(): string
    {
        return public_path();
    }

    /**
     * A builder path — `about/index.html`, or `index.html` for the home page — as an absolute file path.
     * Anything that is not a clean `.html` path inside public/ is refused, so the builder can never be pointed
     * at application code or at a file outside the site.
     */
    public static function resolve(string $path): string
    {
        $path = self::normalise($path);

        if ($path === '') {
            throw ValidationException::withMessages(['path' => 'Choose a page to edit.']);
        }

        $first = explode('/', $path)[0];

        if (in_array(strtolower($first), self::RESERVED, true)) {
            throw ValidationException::withMessages(['path' => 'That address belongs to the system, not to a page.']);
        }

        $absolute = self::root().'/'.$path;

        // Resolve and confirm the result is still inside public/ — `..` is already refused by normalise().
        $real = realpath(dirname($absolute));

        if ($real !== false && ! str_starts_with($real.'/', self::root().'/')) {
            throw ValidationException::withMessages(['path' => 'That address is outside the website.']);
        }

        return $absolute;
    }

    /** The builder path for an absolute path or a loosely written one: `about/` → `about/index.html`. */
    public static function normalise(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = trim(preg_replace('~/+~', '/', $path) ?? $path, '/');

        if ($path === '') {
            return '';
        }

        if (! str_ends_with($path, '.html')) {
            $path .= '/index.html';
        }

        if (str_contains($path, '..')
            || preg_match('~^[a-z0-9][a-z0-9/_-]*\.html$~i', $path) !== 1
            || substr_count($path, '/') > 4) {
            throw ValidationException::withMessages(['path' => 'That is not a page address the builder can open.']);
        }

        return $path;
    }

    /** The URL a page path answers: `about/index.html` → `/about/`. */
    public static function urlOf(string $path): string
    {
        $path = self::normalise($path);

        if ($path === 'index.html') {
            return '/';
        }

        if (str_ends_with($path, '/index.html')) {
            return '/'.substr($path, 0, -strlen('index.html'));
        }

        return '/'.$path;
    }

    /** The builder path for a public URL: `/about/` → `about/index.html`, `/` → `index.html`. */
    public static function pathForUrl(string $url): string
    {
        $path = self::normalise($url);

        return $path === '' ? 'index.html' : $path;
    }

    public static function exists(string $path): bool
    {
        return is_file(self::resolve($path));
    }

    /**
     * Does a page file answer this public URL? The website is the files, and the web server answers a file
     * before the app runs — so a redirect from a URL that still has one would never fire.
     */
    public static function answers(string $url): bool
    {
        try {
            return is_file(self::resolve(self::pathForUrl($url)));
        } catch (ValidationException) {
            return false;
        }
    }

    public static function read(string $path): string
    {
        $absolute = self::resolve($path);

        if (! is_file($absolute)) {
            throw ValidationException::withMessages(['path' => 'There is no page at '.self::urlOf($path).' yet.']);
        }

        return (string) file_get_contents($absolute);
    }

    /** The exact state of the file, so a save can prove it is writing what the editor opened. */
    public static function hash(string $path): string
    {
        $absolute = self::resolve($path);

        return is_file($absolute) ? sha1((string) file_get_contents($absolute)) : '';
    }

    public static function mtime(string $path): int
    {
        $absolute = self::resolve($path);

        return is_file($absolute) ? (int) filemtime($absolute) : 0;
    }

    /**
     * Write the page. The caller is responsible for the guard and the snapshot; this only replaces the file in
     * one step, so a visitor never sees half a page.
     */
    public static function write(string $path, string $html): void
    {
        $absolute = self::resolve($path);
        $directory = dirname($absolute);

        // `@` because two publishes of the same page (a double click, or a retry after a slow save) can reach this
        // line together: one creates the folder, the other's mkdir says "File exists". The check after it is the
        // real answer — a warning must not become an exception that hides the page.
        if (! is_dir($directory) && ! @mkdir($directory, 0o775, true) && ! is_dir($directory)) {
            throw ValidationException::withMessages(['path' => 'The folder for that page could not be created.']);
        }

        $temporary = $absolute.'.tmp-'.bin2hex(random_bytes(4));

        if (file_put_contents($temporary, $html) === false) {
            throw ValidationException::withMessages(['path' => 'The page file could not be written — is public/ writable?']);
        }

        if (! rename($temporary, $absolute)) {
            @unlink($temporary);

            throw ValidationException::withMessages(['path' => 'The page file could not be replaced.']);
        }

        @chmod($absolute, 0o644);
    }

    /**
     * Every page file the site has, in the order a person looks for them: the home page first, then A–Z.
     *
     * @return list<array{path: string, url: string, title: string, mtime: int, size: int}>
     */
    public static function pages(): array
    {
        $root = self::root();
        $rows = [];

        $walker = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $file) use ($root): bool {
                    if (! $file->isDir()) {
                        return true;
                    }

                    $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');

                    return $relative !== ''
                        && ! in_array(strtolower(explode('/', $relative)[0]), self::RESERVED, true)
                        && substr_count($relative, '/') < 4;
                },
            ),
        );

        foreach ($walker as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['html', 'htm'], true)) {
                continue;
            }

            $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');
            $name = $file->getFilename();

            // The site's pages are `…/index.html` plus any stray top-level page file; partials in a folder
            // (a `header.html` somebody added) are not addresses of their own.
            if ($name !== 'index.html' && str_contains($relative, '/')) {
                continue;
            }

            $rows[] = [
                'path' => $relative,
                'url' => self::urlOf($relative),
                'title' => self::titleOf((string) file_get_contents($file->getPathname())),
                'mtime' => (int) $file->getMTime(),
                'size' => (int) $file->getSize(),
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            if ($a['path'] === 'index.html') {
                return -1;
            }

            if ($b['path'] === 'index.html') {
                return 1;
            }

            return strcmp($a['path'], $b['path']);
        });

        return $rows;
    }

    /** The `<title>` of a document, for the page list. */
    public static function titleOf(string $html): string
    {
        if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $match) === 1) {
            $title = trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5));

            return $title === '' ? '(untitled)' : $title;
        }

        return '(untitled)';
    }

    /**
     * Take a page off the site. The HTML is kept as a version first, then the file is moved out of `public/`
     * into `storage/app/private/site-retired/<stamp>/<path>`: nothing is deleted outright, so putting a page
     * back is moving its file home (and the version is there even if that folder is emptied).
     *
     * @return string the path the file was moved to
     */
    public static function retire(string $path, ?User $user = null): string
    {
        $absolute = self::resolve($path);

        if (! is_file($absolute)) {
            throw ValidationException::withMessages(['path' => 'That page is not there any more.']);
        }

        self::snapshot($path, $user, 'retired');

        $target = storage_path('app/private/site-retired/'.now()->format('Ymd-His').'/'.self::normalise($path));

        File::ensureDirectoryExists(dirname($target));
        File::move($absolute, $target);

        // The page's folder usually holds nothing else; leaving `public/about/` behind would be an empty URL.
        @rmdir(dirname($absolute));

        return $target;
    }

    /**
     * Keep the current file as a version before it is overwritten.
     *
     * Versions live in storage, not in the database (the database no longer describes the site), one folder per
     * path: `storage/app/private/builder/<hash>/<timestamp>-<user>.html`. Old ones are pruned so a busy page
     * cannot fill the disk.
     */
    public static function snapshot(string $path, ?User $user = null, string $reason = ''): void
    {
        $absolute = self::resolve($path);

        if (! is_file($absolute)) {
            return;
        }

        $directory = self::versionDirectory($path);

        File::ensureDirectoryExists($directory);

        $stamp = now()->format('Ymd-His').'-'.substr((string) (int) (microtime(true) * 1000), -4);
        $label = $reason !== '' ? '-'.preg_replace('~[^a-z0-9]+~i', '-', $reason) : '';
        $userPart = $user === null ? '' : '-u'.$user->id;

        File::put($directory.'/'.$stamp.$userPart.$label.'.html', (string) file_get_contents($absolute));

        $files = glob($directory.'/*.html') ?: [];
        sort($files);

        foreach (array_slice($files, 0, max(0, count($files) - self::KEEP_VERSIONS)) as $old) {
            @unlink($old);
        }
    }

    /**
     * @return list<array{file: string, size: int, at: string}>
     */
    public static function versions(string $path): array
    {
        $directory = self::versionDirectory($path);
        $files = glob($directory.'/*.html') ?: [];
        rsort($files);

        return array_map(static function (string $file): array {
            $name = basename($file, '.html');
            $parts = explode('-', $name);

            return [
                'file' => basename($file),
                'size' => (int) filesize($file),
                // Ymd-His-ms… → readable date, without pretending to know the seconds exactly.
                'at' => strlen($parts[0] ?? '') === 8
                    ? substr($parts[0], 0, 4).'-'.substr($parts[0], 4, 2).'-'.substr($parts[0], 6, 2)
                        .' '.substr($parts[1] ?? '', 0, 2).':'.substr($parts[1] ?? '', 2, 2)
                    : $name,
            ];
        }, $files);
    }

    /** One kept version's HTML. */
    public static function version(string $path, string $file): string
    {
        $safe = basename($file);

        if (! str_ends_with($safe, '.html')) {
            throw ValidationException::withMessages(['file' => 'That is not a saved version.']);
        }

        $absolute = self::versionDirectory($path).'/'.$safe;

        if (! is_file($absolute)) {
            throw ValidationException::withMessages(['file' => 'That version is not there any more.']);
        }

        return (string) file_get_contents($absolute);
    }

    private static function versionDirectory(string $path): string
    {
        return storage_path('app/private/builder/'.sha1(self::normalise($path)));
    }
}
