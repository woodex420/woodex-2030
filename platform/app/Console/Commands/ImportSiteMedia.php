<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Website\Builder\PageImageAlt;
use App\Domain\Website\Models\Media;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The pages embed two kinds of picture: the photographs in the media library (`/media/...`, uploaded through the
 * admin) and the theme's own files (`/assets/img/...` — the client logos, the service thumbnails). The library
 * only knew the first kind, so its screen could not find or edit 33 of the 95 images the site actually shows.
 *
 * This adds the theme's images as rows of their own: `disk` is `site`, `path` is the file under `public/`, and the
 * alt is the wording the pages already carry for that image. No file is copied or moved; the row points at the
 * file the pages load. Running it again adds nothing, so it is safe in a deploy.
 *
 *   php artisan woodex:import-site-media --dry-run
 *   php artisan woodex:import-site-media
 */
final class ImportSiteMedia extends Command
{
    protected $signature = 'woodex:import-site-media
        {--dry-run : list the images that would be added, without writing rows}';

    protected $description = 'Add the theme images the pages show (assets/img) to the media library, once.';

    public function handle(): int
    {
        $images = $this->imagesOnPages();
        $known = Media::query()->get()
            ->map(fn (Media $media): string => PageImageAlt::stem($media->path))
            ->all();

        $added = 0;
        $skipped = 0;

        foreach ($images as $stem => $image) {
            $file = public_path($image['path']);

            if (in_array($stem, $known, true) || ! is_file($file)) {
                $skipped++;

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("would add  {$image['path']}  (alt: ".($image['alt'] === '' ? '—' : $image['alt']).')');

                continue;
            }

            [$width, $height] = @getimagesize($file) ?: [null, null];

            Media::query()->create([
                'disk' => 'site',
                'path' => $image['path'],
                'alt' => $image['alt'],
                'title' => Str::headline($stem),
                'width' => $width ?: null,
                'height' => $height ?: null,
                'folder' => $this->folder($image['path']),
                'uploaded_by' => null,
            ]);

            $added++;
        }

        $this->info($this->option('dry-run')
            ? count($images).' images on the pages, '.$skipped.' already in the library.'
            : $added.' added, '.$skipped.' already there.');

        return self::SUCCESS;
    }

    /**
     * Every `/assets/img/…` image the pages show, keyed by stem so the responsive variants count once.
     *
     * @return array<string, array{path: string, alt: string}>
     */
    private function imagesOnPages(): array
    {
        /** @var array<string, array{path: ?string, alt: string}> $images */
        $images = [];

        foreach (File::allFiles(public_path()) as $file) {
            if ($file->getFilename() !== 'index.html') {
                continue;
            }

            $html = (string) file_get_contents($file->getPathname());

            preg_match_all('~<img\b[^>]*>~i', $html, $tags);

            foreach ($tags[0] as $tag) {
                if (preg_match('~/assets/img/([^"\'\s>]+)~i', $tag, $match) !== 1) {
                    continue;
                }

                $relative = 'assets/img/'.ltrim(explode('?', $match[1])[0], '/');
                $stem = PageImageAlt::stem(basename($relative));

                $alt = preg_match('~\balt\s*=\s*["\']([^"\']*)["\']~i', $tag, $named) === 1
                    ? trim(html_entity_decode($named[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                    : '';

                $image = $images[$stem] ?? ['path' => null, 'alt' => ''];

                // Prefer the file without a `-480`/`-960` suffix: it is the largest copy and the one the row names.
                if ($image['path'] === null || ($this->isVariant($image['path']) && ! $this->isVariant($relative))) {
                    $image['path'] = $relative;
                }

                if ($image['alt'] === '' && $alt !== '') {
                    $image['alt'] = $alt;
                }

                $images[$stem] = $image;
            }
        }

        ksort($images);

        $resolved = [];

        foreach ($images as $stem => $image) {
            $resolved[$stem] = ['path' => (string) $image['path'], 'alt' => $image['alt']];
        }

        return $resolved;
    }

    private function isVariant(string $path): bool
    {
        return preg_match('~-\d+w?\.[a-z]+$~i', $path) === 1;
    }

    /** `assets/img/clients/attock.webp` is filed under `site/clients`; the flat ones under `site/img`. */
    private function folder(string $path): string
    {
        $directory = str_replace('\\', '/', dirname($path));

        return $directory === 'assets/img'
            ? 'site/img'
            : 'site/'.ltrim(substr($directory, strlen('assets/img/')), '/');
    }
}
