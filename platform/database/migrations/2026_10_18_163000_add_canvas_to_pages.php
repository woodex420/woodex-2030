<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2-2 leftover (D-107, docs/12 §5.3): the campaign free canvas. A page whose template is
 * "canvas" renders hand-placed HTML instead of structured sections — the one page type where a
 * hand-placed layout is acceptable. The sections stay in place underneath, dormant, so switching
 * back to the structured editor loses nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Fresh installs get the column earlier (2026_10_16_000350, which explains why); the
        // guard makes this file a no-op there instead of a duplicate-column crash.
        if (Schema::hasColumn('pages', 'canvas_html')) {
            return;
        }

        Schema::table('pages', function (Blueprint $table): void {
            $table->text('canvas_html')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('pages', 'canvas_html')) {
            return;
        }

        Schema::table('pages', function (Blueprint $table): void {
            $table->dropColumn('canvas_html');
        });
    }
};
