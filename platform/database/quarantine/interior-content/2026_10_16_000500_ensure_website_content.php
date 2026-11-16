<?php

declare(strict_types=1);

use App\Domain\Website\Models\Page;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * A second chance for the pages (docs/09 §13).
 *
 * The migration that ships them cannot write them on a database that has no owner yet, and other things can keep
 * them out too. This one asks a single question — is the website empty? — and fills it when it is. It never runs on
 * a site that already has pages, so nothing the owner has written can be overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests() || ! is_file(base_path('database/data/website-bundle/pages.json'))) {
            return;
        }

        if (Page::query()->exists()) {
            return;
        }

        Artisan::call('woodex:import-website');
    }

    public function down(): void
    {
        // The pages are content, not schema: nothing is deleted by rolling back.
    }
};
