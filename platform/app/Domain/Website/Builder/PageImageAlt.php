<?php

declare(strict_types=1);

namespace App\Domain\Website\Builder;

use App\Domain\Website\Models\Media;
use Illuminate\Support\Facades\File;

/**
 * Alt text of a photo lives in the page files (Woodex OS 1). The media row keeps the wording as its record; this
 * writes the same wording into every `<img>` that shows the image, so the Media screen and the site agree.
 *
 * An image is matched by its **stem**, not its exact filename: the pages embed `img-abc.webp` and its
 * `img-abc-480.webp` / `img-abc-960.webp` variants through `srcset`, and all of them are the same photograph with
 * one alt. The library keeps a row per file, so editing any of them edits the picture.
 */
final class PageImageAlt
{
    /** `…/img-abc-960.webp?v=3` -> `img-abc`: the responsive suffix is not part of the image's identity. */
    public static function stem(string $file): string
    {
        $path = parse_url($file, PHP_URL_PATH);
        $name = pathinfo(is_string($path) && $path !== '' ? $path : $file, PATHINFO_FILENAME);

        return (string) preg_replace('~-\d+w?$~i', '', $name);
    }

    /**
     * The page files that show this image, as public-relative paths (`about/index.html`).
     *
     * @return list<string>
     */
    public static function pagesUsing(string $stem): array
    {
        $pages = [];

        foreach (self::files() as $file) {
            $html = (string) file_get_contents($file);

            if (self::apply($html, $stem, null)['images'] > 0) {
                $pages[] = self::relative($file);
            }
        }

        return $pages;
    }

    /**
     * Writes the alt into every `<img>` of every page that shows the image.
     *
     * @return array{pages: int, images: int} pages and tags actually changed
     */
    public static function write(string $stem, string $alt): array
    {
        $pages = 0;
        $images = 0;

        foreach (self::files() as $file) {
            $html = (string) file_get_contents($file);
            $result = self::apply($html, $stem, $alt);

            if (! $result['changed']) {
                continue;
            }

            file_put_contents($file, $result['html']);
            $pages++;
            $images += $result['images'];
        }

        return ['pages' => $pages, 'images' => $images];
    }

    /**
     * @return array{html: string, images: int, changed: bool}
     */
    private static function apply(string $html, string $stem, ?string $alt): array
    {
        $images = 0;
        $changed = false;

        $out = preg_replace_callback('~<img\b[^>]*>~i', static function (array $match) use ($stem, $alt, &$images, &$changed): string {
            $tag = $match[0];

            if (! self::shows($tag, $stem)) {
                return $tag;
            }

            $images++;

            if ($alt === null) {
                return $tag;
            }

            $new = self::withAlt($tag, $alt);
            $changed = $changed || $new !== $tag;

            return $new;
        }, $html);

        return ['html' => $out ?? $html, 'images' => $images, 'changed' => $changed];
    }

    /** Does this `<img>` show the image? Its `src` or any `srcset` candidate decides. */
    private static function shows(string $tag, string $stem): bool
    {
        $urls = [];

        if (preg_match('~\bsrc\s*=\s*["\']([^"\']+)["\']~i', $tag, $match) === 1) {
            $urls[] = $match[1];
        }

        if (preg_match('~\bsrcset\s*=\s*["\']([^"\']+)["\']~i', $tag, $match) === 1) {
            foreach (explode(',', $match[1]) as $candidate) {
                $url = trim(explode(' ', trim($candidate))[0]);
                if ($url !== '') {
                    $urls[] = $url;
                }
            }
        }

        foreach ($urls as $url) {
            if (self::stem($url) === $stem) {
                return true;
            }
        }

        return false;
    }

    /**
     * The tag with its alt replaced, or with one added when it has none. Only that attribute changes: the rest of
     * the tag is left exactly as the builder wrote it.
     */
    private static function withAlt(string $tag, string $alt): string
    {
        $value = htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if (preg_match('~\balt\s*=\s*(["\'])(.*?)\1~is', $tag) === 1) {
            return (string) preg_replace_callback(
                '~\balt\s*=\s*(["\'])(.*?)\1~is',
                static fn (): string => 'alt="'.$value.'"',
                $tag,
                1,
            );
        }

        return (string) preg_replace_callback(
            '~\s*/?>$~',
            static fn (array $match): string => ' alt="'.$value.'"'.$match[0],
            $tag,
            1,
        );
    }

    /**
     * Every page file: `public/**\/index.html` — the 142 addresses the site serves.
     *
     * @return list<string>
     */
    private static function files(): array
    {
        $files = [];

        foreach (File::allFiles(public_path()) as $file) {
            if ($file->getFilename() === 'index.html') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private static function relative(string $file): string
    {
        return str_replace('\\', '/', str_replace(rtrim(public_path(), '/').'/', '', $file));
    }
}
