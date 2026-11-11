<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The columns the website import writes, backdated (fresh installs only).
 *
 * SavePage has written pages.canvas_html and page_blocks.block_key since the V2-2 canvas and
 * the V2-3 click-to-edit — so every migration that imports or repairs pages
 * (2026_10_16_000400 onwards, and the 2026_10_17_000200 reload) needs both columns to exist
 * before it runs. On a database migrated step by step as the waves landed, the ALTER
 * migrations (2026_10_18_151000, 2026_10_18_163000) had already run and nothing noticed. On a
 * FRESH install the migrations run in file order, the importers ran first, and the very first
 * page save died. This one sorts before the first importer, so the columns are there from
 * the start.
 *
 * The guards keep every path safe: fresh installs get the columns here and the two ALTER
 * migrations no-op over them; databases that already ran the ALTERs run this file later, see
 * the columns, and do nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pages', 'canvas_html')) {
            Schema::table('pages', function (Blueprint $table): void {
                $table->text('canvas_html')->nullable();
            });
        }

        if (! Schema::hasColumn('page_blocks', 'block_key')) {
            Schema::table('page_blocks', function (Blueprint $table): void {
                $table->string('block_key', 40)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pages', 'canvas_html')) {
            Schema::table('pages', function (Blueprint $table): void {
                $table->dropColumn('canvas_html');
            });
        }

        if (Schema::hasColumn('page_blocks', 'block_key')) {
            Schema::table('page_blocks', function (Blueprint $table): void {
                $table->dropColumn('block_key');
            });
        }
    }
};
