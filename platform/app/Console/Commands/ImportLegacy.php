<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\System\Models\LegacyRef;
use App\Domain\Website\Data\LegacyUrlMap;
use App\Domain\Website\Import\ImportAuthor;
use App\Domain\Website\Import\ImportLegacyPage;
use App\Domain\Website\Import\LinkImportedPages;
use App\Domain\Website\Models\Page;
use Illuminate\Console\Command;

/**
 * Imports the previous website into the CMS (docs/09 §13).
 *
 *   php artisan woodex:import-legacy --path=/path/to/frontend-v1
 *
 * Pages arrive as drafts: nothing is published until a person has read it (D-70). The run is idempotent — a second
 * run updates the pages whose file changed and reports the rest as unchanged, so re-running after a new export is
 * safe. `--publish` publishes as it imports; use it only for pages you have already read.
 */
final class ImportLegacy extends Command
{
    protected $signature = 'woodex:import-legacy
        {--path= : folder that holds the old site (the one with index.html in it)}
        {--only= : import one page (a slug, or a few separated by commas)}
        {--publish : publish the imported pages instead of leaving them as drafts}
        {--force : import again even when the file has not changed}
        {--dry-run : parse everything and report, but write nothing}';

    protected $description = 'Import the pages of the previous website into the CMS (drafts by default).';

    public function handle(ImportLegacyPage $import, LinkImportedPages $link, ImportAuthor $author): int
    {
        $root = (string) $this->option('path');

        if ($root === '') {
            $this->components->error('Give the folder of the old site: --path=/path/to/frontend-v1');

            return self::FAILURE;
        }

        if (! is_dir($root)) {
            $this->components->error('There is no folder at '.$root);

            return self::FAILURE;
        }

        $only = $this->only();
        $dryRun = (bool) $this->option('dry-run');
        $publish = (bool) $this->option('publish');
        $force = (bool) $this->option('force');
        $user = $author->first();

        if ($user === null) {
            $this->components->error('There is no user yet: install the app before importing pages.');

            return self::FAILURE;
        }

        $rows = [];
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'missing' => 0];
        $blocks = 0;
        $images = 0;

        foreach (LegacyUrlMap::pages() as $slug => $page) {
            if ($only !== [] && ! in_array($slug, $only, true)) {
                continue;
            }

            // A page the old site did not have (like /furniture/) starts from the closest old page and is rewritten
            // by hand afterwards, so the seed is used only while the page does not exist yet.
            $exists = Page::query()->where('slug', $slug)->exists()
                || LegacyRef::query()->where('legacy_table', 'website-page')->where('legacy_id', $slug)->exists();
            $source = $page['source'] ?? ($exists ? null : ($page['seed_from'] ?? null));

            if ($source === null) {
                $rows[] = [$slug, 'no source', '-', '-', 'a new page: write it in the editor'];

                continue;
            }

            if ($dryRun) {
                $file = rtrim($root, '/').'/'.$source;
                $rows[] = [$slug, is_file($file) ? 'would import' : 'missing file', '-', '-', ''];

                continue;
            }

            $result = $import($root, $source, $slug, LegacyUrlMap::typeFor($slug), $user, $publish, $force);

            if ($page['source'] === null) {
                $result['issues'][] = 'seeded from '.$source.': rewrite it before publishing';
            }

            if (isset($counts[$result['status']])) {
                $counts[$result['status']]++;
            }

            $blocks += $result['blocks'];
            $images += $result['images'];

            if ($result['status'] !== 'unchanged' || $this->output->isVerbose()) {
                $rows[] = [$slug, $result['status'], (string) $result['blocks'], (string) $result['images'], implode('; ', $result['issues'])];
            }
        }

        $this->components->twoColumnDetail('Old URLs in the map', (string) LegacyUrlMap::count());
        $this->components->twoColumnDetail('Pages to import', (string) count(LegacyUrlMap::pages()));
        $this->components->twoColumnDetail('URLs to redirect', (string) count(LegacyUrlMap::redirects()));

        if ($rows !== []) {
            $this->newLine();
            $this->table(['Page', 'Result', 'Sections', 'Images', 'Notes'], $rows);
        }

        if (! $dryRun) {
            $linked = $link();

            $this->newLine();
            $this->components->info(sprintf(
                '%d created · %d updated · %d unchanged · %d missing · %d sections · %d images · %d link blocks updated.',
                $counts['created'],
                $counts['updated'],
                $counts['unchanged'],
                $counts['missing'],
                $blocks,
                $images,
                $linked,
            ));

            if (! $publish) {
                $this->components->warn('The pages are drafts. Read them in Admin → Website → Pages, then publish.');
            }
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function only(): array
    {
        $only = trim((string) $this->option('only'));

        if ($only === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $slug): string => '/'.trim(trim($slug), '/').'/',
            explode(',', $only),
        )));
    }
}
