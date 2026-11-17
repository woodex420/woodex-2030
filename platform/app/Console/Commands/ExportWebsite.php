<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Website\Models\Media;
use App\Domain\Website\Models\Page;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Writes the website (pages, sections and the images they use) to a folder that can be committed to the repository.
 *
 * The old HTML lives on a laptop, not on the server: the site is imported once here, and this folder is how the
 * pages and their photographs travel to Hostinger. `woodex:import-website` reads it back, and the migration that
 * ships it runs with the owner's one-click "Database update".
 *
 *   php artisan woodex:export-website --to=database/data/website-bundle
 */
final class ExportWebsite extends Command
{
    protected $signature = 'woodex:export-website {--to=database/data/website-bundle : folder to write}';

    protected $description = 'Export the public website (pages, sections, images) to a folder for the repository.';

    public function handle(): int
    {
        $to = (string) $this->option('to');
        $base = str_starts_with($to, '/') ? $to : base_path($to);
        $mediaDir = $base.'/media';

        if (! is_dir($mediaDir)) {
            mkdir($mediaDir, 0o775, true);
        }

        $pages = Page::query()->with('blocks')->orderBy('slug')->get();
        $media = Media::query()->get()->keyBy('id');
        $files = [];
        $bytes = 0;
        $export = [];

        foreach ($pages as $page) {
            $blocks = [];

            foreach ($page->blocks as $block) {
                $data = (array) $block->data;

                // The images a block uses decide which files travel with the export.
                foreach ($this->imageIds($data) as $id) {
                    $files[$id] = true;
                }

                $blocks[] = ['type' => $block->type->value, 'data' => $data, 'visible' => $block->visible];
            }

            foreach (['og_image_id' => ($page->seo['og_image_id'] ?? null), 'featured_image_id' => $page->featured_image_id] as $id) {
                if (is_numeric($id)) {
                    $files[(int) $id] = true;
                }
            }

            $export[] = [
                'slug' => $page->slug,
                'type' => $page->type->value,
                'title' => $page->title,
                'excerpt' => $page->excerpt,
                'status' => $page->status->value,
                'published_at' => $page->published_at?->toIso8601String(),
                'seo' => $page->seo,
                'schema_type' => $page->schema_type,
                'sort' => $page->sort,
                'blocks' => $blocks,
            ];
        }

        $manifest = [];

        foreach (array_keys($files) as $id) {
            $image = $media->get($id);

            if ($image === null || ! Storage::disk($image->disk)->exists($image->path)) {
                continue;
            }

            $contents = (string) Storage::disk($image->disk)->get($image->path);
            $name = basename($image->path);
            // Written byte for byte: the import deduplicates images by their content.
            file_put_contents($mediaDir.'/'.$name, $contents);
            $bytes += strlen($contents);

            $manifest[] = [
                'id' => $image->id,
                'file' => $name,
                'alt' => $image->alt,
                'title' => $image->title,
                'width' => $image->width,
                'height' => $image->height,
                'folder' => $image->folder,
            ];
        }

        file_put_contents($base.'/pages.json', json_encode([
            'exported_at' => now()->toIso8601String(),
            'pages' => $export,
            'media' => $manifest,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $this->components->info(sprintf(
            '%d pages, %d sections, %d images (%.1f MB) written to %s.',
            count($export),
            array_sum(array_map(fn (array $page): int => count($page['blocks']), $export)),
            count($manifest),
            $bytes / 1048576,
            $to,
        ));

        return self::SUCCESS;
    }

    /**
     * The media ids a section uses, wherever they appear in its data.
     *
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private function imageIds(array $data): array
    {
        $ids = [];

        foreach ($data as $key => $value) {
            if (($key === 'image_id' || $key === 'id') && is_numeric($value)) {
                $ids[] = (int) $value;
            }

            if (is_array($value)) {
                foreach ($value as $row) {
                    if (is_array($row)) {
                        $ids = [...$ids, ...$this->imageIds($row)];
                    }
                }
            }
        }

        return $ids;
    }
}
