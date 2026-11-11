<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Descriptions for the photographs that were imported without one (docs/09 §13).
 *
 * The first import named an image after its file when the old site gave no alt text — "img 27c481fa9a3d" says
 * nothing to a search engine and nothing to a screen reader. This puts right what the bundle now knows, and never
 * touches a description the owner has written.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests() || ! is_file(base_path('database/data/website-bundle/pages.json'))) {
            return;
        }

        // Photographs only: the pages are the owner's to edit, and this must never overwrite one.
        Artisan::call('woodex:import-website', ['--media' => true]);
    }

    public function down(): void
    {
        // The pages are content, not schema: nothing is deleted by rolling back.
    }
};
