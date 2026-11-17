<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2-3 navigation (docs/14): a top-level item with children can open as a wide mega panel instead of a
 * plain dropdown — one switch per item, decided by whoever builds the menu (owner answer, 18 Oct).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('navigation_items', function (Blueprint $table): void {
            $table->boolean('is_mega')->default(false)->after('target');
        });
    }

    public function down(): void
    {
        Schema::table('navigation_items', function (Blueprint $table): void {
            $table->dropColumn('is_mega');
        });
    }
};
