<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The interior brand accent was seeded as an invented navy (#0f1e36) before the v2.1 print
     * source was available; align untouched installs to the source ink (#0c1628). Any accent the
     * owner actually chose in Document design is left alone.
     */
    public function up(): void
    {
        DB::table('document_designs')->where('accent_color', '#0f1e36')->update(['accent_color' => '#0c1628']);
    }

    public function down(): void
    {
        DB::table('document_designs')->where('accent_color', '#0c1628')->update(['accent_color' => '#0f1e36']);
    }
};
