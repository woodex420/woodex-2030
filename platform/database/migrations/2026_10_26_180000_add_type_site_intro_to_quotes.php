<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Slice S (master plan 34): the builder fields v2.1 Pro carries on every quotation —
     * work type (Renovation, Fit-out…), site, and the intro line printed on the summary page.
     * All optional; existing quotations print exactly as before.
     */
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->string('type', 60)->nullable()->after('title');
            $table->string('site', 160)->nullable()->after('type');
            $table->string('intro_line', 500)->nullable()->after('site');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropColumn(['type', 'site', 'intro_line']);
        });
    }
};
