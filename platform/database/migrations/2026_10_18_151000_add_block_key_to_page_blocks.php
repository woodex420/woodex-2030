<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * V2-3 click-to-edit (docs/14): every section carries a stable key, so the section the owner clicks
 * in the preview can be found again in the editor — across saves, reorders and restores. Rows from
 * before this migration get one now, so every existing section is clickable from day one.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Fresh installs get the column earlier (2026_10_16_000350, which explains why); the
        // guard makes this file a no-op there instead of a duplicate-column crash. (No ->after()
        // here because SQLite ignores it anyway.)
        if (Schema::hasColumn('page_blocks', 'block_key')) {
            return;
        }

        Schema::table('page_blocks', function (Blueprint $table): void {
            $table->string('block_key', 40)->nullable();
        });

        // Existing sections were born without a key; give each one its own.
        DB::table('page_blocks')->whereNull('block_key')->orderBy('id')->each(function (object $block): void {
            DB::table('page_blocks')->where('id', $block->id)->update(['block_key' => 'b'.Str::lower(Str::random(8))]);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('page_blocks', 'block_key')) {
            return;
        }

        Schema::table('page_blocks', function (Blueprint $table): void {
            $table->dropColumn('block_key');
        });
    }
};
