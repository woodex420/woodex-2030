<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2-3 F6 (docs/14 §5a): the shared content library — FAQs, testimonials and client logos managed
 * once and used on any page. Each entry stores its data in the same shape the matching section
 * uses, so a page's section simply merges the entries it points at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 20);                 // faq | testimonial | logo
            $table->json('data');                       // the section row: {question, answer} / {text, name, when, source} / {media_id, alt}
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['kind', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_entries');
    }
};
