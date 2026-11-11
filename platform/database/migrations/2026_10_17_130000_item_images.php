<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item pictures (P8.0): a rate book entry can carry one product photo, and the quotation builder
 * copies the picture onto the quote line — a furniture quotation shows the item next to its
 * description (the "quotation 10153" style). Copying keeps the rule that later catalogue edits
 * never change existing quotations: the line keeps the path it was given at insertion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table): void {
            $table->string('image_path')->nullable()->after('description');
        });

        Schema::table('quote_items', function (Blueprint $table): void {
            $table->string('image_path')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table): void {
            $table->dropColumn('image_path');
        });

        Schema::table('quote_items', function (Blueprint $table): void {
            $table->dropColumn('image_path');
        });
    }
};
