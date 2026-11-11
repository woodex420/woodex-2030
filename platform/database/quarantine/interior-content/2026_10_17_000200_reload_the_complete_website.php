<?php

use App\Domain\System\Models\LegacyRef;
use App\Domain\Website\Actions\AddMissingCallToActions;
use App\Domain\Website\Actions\LinkCityPagesToAreas;
use App\Domain\Website\Actions\LinkEmptyServiceUrls;
use App\Domain\Website\Actions\RepairImportedCopy;
use App\Domain\Website\Models\Page;
use App\Domain\Website\Services\Menus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * The complete old website, loaded in one go (owner's decision, 17 Oct 2026 — docs/10).
 *
 * Until now 89 of the old site's 143 URLs were pages and the other 54 were 301s to the nearest discipline: a good
 * SEO shape, but it meant more than a third of the studio's own pages were invisible in the admin and could not be
 * edited. The owner asked for the whole website: every old URL is now a page the studio can open, rewrite and
 * publish with the block builder.
 *
 * So the public website is emptied and `database/data/website-bundle` (143 published pages, 1 448 sections and 105
 * photographs, exported by `woodex:export-website`) is loaded in its place, in the same Database update — the site
 * is never blank. The three content fixes from the audit run again afterwards, because an import brings the old
 * pages back without them.
 *
 * It runs once: the checksum of the bundle is recorded, and a later Database update with the same bundle changes
 * nothing, so pages the studio has since edited are never thrown away.
 */
return new class extends Migration
{
    private const string MARKER = 'website-reload';

    public function up(): void
    {
        // Website tests build their own pages, and a complete website in the test database would collide with
        // every slug they use — the other content migrations stand aside for the same reason (D-94).
        if (app()->runningUnitTests()) {
            return;
        }

        $checksum = (string) hash_file('sha256', base_path('database/data/website-bundle/pages.json'));

        if (LegacyRef::query()->where('legacy_table', self::MARKER)->where('checksum', $checksum)->exists()
            && Page::query()->count() >= 143) {
            return; // This bundle is already the website. Nothing to do.
        }

        // Empty the public website: pages, sections, photographs, the old 301s, and the import bookkeeping that
        // remembers what has already been imported (which is what made a second import skip everything).
        Artisan::call('woodex:reset-website', ['--force' => true]);
        Artisan::call('woodex:import-website', ['--path' => 'database/data/website-bundle', '--force' => true]);

        // The fixes the 143-page audit asked for, applied again because the import cannot know them.
        (new AddMissingCallToActions)();
        (new LinkCityPagesToAreas)();
        (new LinkEmptyServiceUrls)();
        (new RepairImportedCopy)();

        LegacyRef::query()->updateOrCreate(
            ['legacy_table' => self::MARKER, 'legacy_id' => 'pages.json'],
            [
                'model_type' => Page::class,
                'model_id' => (int) (Page::query()->where('slug', '/')->value('id') ?? 0),
                'checksum' => $checksum,
                'imported_at' => now(),
            ],
        );

        Menus::flush();
    }

    public function down(): void
    {
        LegacyRef::query()->where('legacy_table', self::MARKER)->delete();
    }
};
