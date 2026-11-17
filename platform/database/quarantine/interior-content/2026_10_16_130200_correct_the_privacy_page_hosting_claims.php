<?php

use App\Domain\Website\Actions\RepairImportedCopy;
use Illuminate\Database\Migrations\Migration;

/**
 * The privacy page said our data lived in Supabase and the site was served by Vercel (docs/10). It does not: the
 * data sits in this application's own database and the site is served from the studio's own hosting. A privacy
 * notice is a promise, and this is the one page written to be trusted.
 *
 * The correction lives in RepairImportedCopy, because importing the old website brings the old wording back.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new RepairImportedCopy)();
    }

    public function down(): void
    {
        // The blocks these actions write are ordinary sections: the studio removes them in Website -> Pages,
        // or simply re-imports the page.
    }
};
