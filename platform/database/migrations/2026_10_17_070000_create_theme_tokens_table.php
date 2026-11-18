<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * V2-1 (docs/12 §5.1, D-116): the site's design tokens, v1's own palette expressed as data. The public
 * pages print them as CSS custom properties (/theme/{hash}.css), so changing a value here re-skins every
 * page without a rebuild — Tailwind v4 utilities read these variables at runtime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theme_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('group', 20);          // colour | typography | shape
            $table->string('key', 60)->unique();  // --color-navy-900
            $table->string('value', 200);         // #0F1E36
            $table->string('label', 80);          // Brand navy
            $table->string('type', 10)->default('color'); // color | font | text
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_tokens');
    }
};
