<?php

use App\Domain\Commerce\Actions\InstallCatalogueTaxonomy;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(InstallCatalogueTaxonomy::class)->handle();
    }

    public function down(): void
    {
        // Forward-only data install. Categories may have been customized or linked; do not delete them.
    }
};
