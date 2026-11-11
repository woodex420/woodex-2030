<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * The pages of the website, as content rather than code (docs/09 §13, D-67).
 *
 * The old HTML lives on a laptop, not on the server, so the pages travel in `database/data/website-bundle`
 * (`woodex:export-website` writes it) and are imported here — which means the owner's one-click "Database update"
 * is all it takes to put them on the live site. The import is idempotent: it runs once per bundle and never
 * overwrites a page the owner has since edited.
 *
 * On a database with no owner yet there is nobody to write the pages as, so the installer imports them the moment
 * the owner account exists (`woodex:install` and the web installer both do).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Content, not schema: tests bring their own pages, and a fresh test database must stay empty of them.
        if (app()->runningUnitTests() || ! is_file(base_path('database/data/website-bundle/pages.json'))) {
            return;
        }

        if (! User::query()->exists()) {
            return;
        }

        Artisan::call('woodex:import-website');
    }

    public function down(): void
    {
        // The pages are content, not schema: nothing is deleted by rolling back.
    }
};
