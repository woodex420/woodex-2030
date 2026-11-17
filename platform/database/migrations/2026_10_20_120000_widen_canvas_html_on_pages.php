<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Visual Page Builder stores a whole page document (head + body), not a fragment. The v1 home
 * page is 130 KB and the builder's own ceiling is 3 MB, so TEXT (64 KB) cannot hold it. This only
 * widens the existing column — no new columns, no data rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table): void {
            $table->longText('canvas_html')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reversible in type only: MySQL truncates a document longer than 64 KB, so rolling back is
        // safe only while every canvas page is small.
        Schema::table('pages', function (Blueprint $table): void {
            $table->text('canvas_html')->nullable()->change();
        });
    }
};
