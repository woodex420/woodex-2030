<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * CNV-004: saved replies the team actually sends, with variables ({first_name}, {studio_hours},
 * {quote_link}, {studio_name}) filled in per conversation at pick time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canned_replies', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->text('body');
            $table->string('locale', 8)->default('en');
            $table->string('keywords', 160)->default('');
            $table->unsignedInteger('usage_count')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canned_replies');
    }
};
