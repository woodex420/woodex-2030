<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

/**
 * Copies the website's page files into storage (Woodex OS 1, docs/audit/16.19).
 *
 * The site is `public/` now: the v1 pages ship in the repository, and every page the owner publishes from the
 * OS — an article, a study, a page built in the builder — is a new file on that server only. A deploy that
 * replaces the folder, a re-clone, or a broken upload would take those pages with it, and no row still holds
 * their words. This writes one dated zip of every `index.html` under public/ into
 * `storage/app/private/site-backups/`, which the deployment's data folder never touches.
 *
 * It backs up **pages**, not the theme's assets: `assets/`, `build/`, `media/` and the application's own
 * folders come from the repository and from storage, and copying them daily would grow without bound.
 *
 *   php artisan woodex:site-backup            # today's copy, keeps 7
 *   php artisan woodex:site-backup --keep=30
 */
final class SiteBackup extends Command
{
    /** Folders under public/ that are never pages: the app's own URLs and the site's assets. */
    private const array SKIP = ['admin', 'api', 'assets', 'build', 'builder', 'img', 'livewire', 'media', 'storage', 'vendor', 'webhooks'];

    protected $signature = 'woodex:site-backup {--keep=7 : how many dated copies to keep}';

    protected $description = 'Zip every page file under public/ into storage, and keep the last few copies.';

    public function handle(): int
    {
        $root = public_path();

        if (! is_dir($root)) {
            $this->error('There is no public folder to back up at '.$root);

            return self::FAILURE;
        }

        $pages = $this->pages($root);

        if ($pages === []) {
            $this->error('No page files were found under '.$root.' — nothing was written.');

            return self::FAILURE;
        }

        $folder = storage_path('app/private/site-backups');

        if (! is_dir($folder) && ! @mkdir($folder, 0o775, true) && ! is_dir($folder)) {
            $this->error('The backup folder could not be created: '.$folder);

            return self::FAILURE;
        }

        $name = 'site-'.now()->format('Ymd-His').'.zip';
        $zip = new ZipArchive;

        if ($zip->open($folder.'/'.$name, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error('The backup file could not be opened for writing.');

            return self::FAILURE;
        }

        foreach ($pages as $relative) {
            $zip->addFile($root.'/'.$relative, $relative);
        }

        $zip->close();

        $kept = $this->prune($folder, max(1, (int) $this->option('keep')));

        $this->info(sprintf(
            'Backed up %d page file(s) to storage/app/private/site-backups/%s — %s, %d copy/copies kept.',
            count($pages),
            $name,
            $this->size(filesize($folder.'/'.$name) ?: 0),
            $kept,
        ));

        return self::SUCCESS;
    }

    /**
     * Every page file, as a path relative to public/: the site's own `index.html` files, and nothing from the
     * folders that belong to the application or to the theme.
     *
     * @return list<string>
     */
    private function pages(string $root): array
    {
        $found = [];

        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($walk as $item) {
            if (! $item->isFile() || strtolower($item->getFilename()) !== 'index.html') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
            $first = strtolower((string) strtok($relative, '/'));

            if (in_array($first, self::SKIP, true)) {
                continue;
            }

            $found[] = $relative;
        }

        sort($found);

        return $found;
    }

    /** Keep the newest `$keep` copies; return how many are left. */
    private function prune(string $folder, int $keep): int
    {
        $files = glob($folder.'/site-*.zip') ?: [];

        usort($files, static fn (string $a, string $b): int => strcmp(basename($b), basename($a)));

        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
        }

        return min(count($files), $keep);
    }

    private function size(int $bytes): string
    {
        return $bytes > 1048576 ? round($bytes / 1048576, 1).' MB' : round($bytes / 1024).' KB';
    }
}
