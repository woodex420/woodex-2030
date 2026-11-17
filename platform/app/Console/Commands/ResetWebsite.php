<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\System\Models\LegacyRef;
use App\Domain\Website\Models\Media;
use App\Domain\Website\Models\Page;
use App\Domain\Website\Models\PageBlock;
use App\Domain\Website\Models\PageVersion;
use App\Domain\Website\Models\Redirect;
use App\Domain\Website\Services\Menus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Empties the public website: every page, every section, every photograph, and the import bookkeeping that
 * remembers what has already been imported (docs/09 §13, audit docs/10).
 *
 * This is the "start again" switch the owner reaches for when a whole new frontend is about to be loaded. It is
 * deliberately loud about what it is about to do and asks before doing it.
 *
 *   php artisan woodex:reset-website            # shows what would go, asks
 *   php artisan woodex:reset-website --force    # does it
 *   php artisan woodex:reset-website --keep-media --keep-redirects
 *
 * Redirects and menus are the studio's own work, not imported content, so they are kept unless asked for.
 */
final class ResetWebsite extends Command
{
    /** @var list<string> Every import that wrote website rows, so a re-import is not blocked by its own bookkeeping. */
    private const array IMPORT_REFS = ['website-bundle', 'website-page', 'website-image', 'legacy-website'];

    protected $signature = 'woodex:reset-website
        {--force : really delete, without asking}
        {--keep-media : leave the media library (and the files on disk) alone}
        {--keep-redirects : leave the 301 map alone}';

    protected $description = 'Delete every page, section and photograph of the public website (docs/09 §13).';

    public function handle(): int
    {
        $blocks = PageBlock::query()->count();
        $versions = PageVersion::query()->count();
        $pages = Page::query()->withTrashed()->count();
        $media = Media::query()->count();
        $redirects = Redirect::query()->count();

        $this->components->info('This deletes the public website:');
        $this->components->twoColumnDetail('pages (incl. trashed)', (string) $pages);
        $this->components->twoColumnDetail('sections', (string) $blocks);
        $this->components->twoColumnDetail('saved versions', (string) $versions);
        $this->components->twoColumnDetail('photographs', $this->option('keep-media') ? 'kept' : (string) $media);
        $this->components->twoColumnDetail('redirects', $this->option('keep-redirects') ? 'kept' : (string) $redirects);

        if (! $this->option('force') && ! $this->components->confirm('Delete all of it?', default: false)) {
            $this->components->warn('Nothing was deleted. Add --force to run it without asking.');

            return self::SUCCESS;
        }

        DB::transaction(function (): void {
            PageBlock::query()->delete();
            PageVersion::query()->delete();
            Page::query()->withTrashed()->forceDelete();

            if (! $this->option('keep-media')) {
                foreach (Media::query()->get() as $image) {
                    // The file itself goes too, otherwise the library fills with orphans the studio cannot see.
                    Storage::disk($image->disk)->delete($image->path);
                }

                Media::query()->delete();
            }

            if (! $this->option('keep-redirects')) {
                Redirect::query()->delete();
            }

            // So the next `woodex:import-website` is not skipped as "already imported".
            LegacyRef::query()->whereIn('legacy_table', self::IMPORT_REFS)->delete();
        });

        // Cached page HTML expires on its own: a key carries the page id and its updated_at, and the router
        // resolves a URL to a page in the database first, so a deleted page is a 404 no matter what is cached.
        Menus::flush();

        $this->components->info(sprintf(
            'Deleted %d pages, %d sections and %d versions. The website is now empty.',
            $pages,
            $blocks,
            $versions,
        ));

        return self::SUCCESS;
    }
}
