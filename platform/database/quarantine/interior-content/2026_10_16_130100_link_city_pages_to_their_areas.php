<?php

use App\Domain\Website\Actions\LinkCityPagesToAreas;
use Illuminate\Database\Migrations\Migration;

/**
 * A city page points at the areas inside it (audit of 16 Oct 2026, docs/10 §3.3).
 *
 * The eight Lahore area pages are the pages a person in Lahore actually searches for, and nothing linked to them.
 * The work lives in LinkCityPagesToAreas so it can run again after every re-import.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new LinkCityPagesToAreas)();
    }

    public function down(): void
    {
        // The blocks these actions write are ordinary sections: the studio removes them in Website -> Pages,
        // or simply re-imports the page.
    }
};
