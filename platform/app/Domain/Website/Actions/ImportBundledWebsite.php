<?php

declare(strict_types=1);

namespace App\Domain\Website\Actions;

use Illuminate\Support\Facades\Artisan;

/**
 * Puts the pages that travel with the code on the site (docs/09 §13).
 *
 * The old HTML lives on a laptop, so the pages and their photographs are exported to
 * `database/data/website-bundle` and read back by `woodex:import-website`. The migration does that on a database
 * that already has an owner; on a brand-new one there is nobody to write the pages yet, so the installer calls this
 * action the moment the owner exists. The import is idempotent and never touches a page the owner has edited since.
 */
final class ImportBundledWebsite
{
    /**
     * @return string|null the output of the importer, or null when there is no bundle on this installation
     */
    public function __invoke(): ?string
    {
        // Tests bring their own pages, and a test database must stay empty of the shipped ones — the migration
        // that ships them makes the same exception.
        if (app()->runningUnitTests() || ! is_file(base_path('database/data/website-bundle/pages.json'))) {
            return null;
        }

        Artisan::call('woodex:import-website');

        return trim(Artisan::output());
    }
}
