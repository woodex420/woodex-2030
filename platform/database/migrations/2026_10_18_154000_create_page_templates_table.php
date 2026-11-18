<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2-3 F5 (docs/14 §5a): page templates — a named, ordered section list per page type. The studio
 * picks one when starting a page, or applies one to an existing page; the template decides the
 * layout (which sections, in what order), never the text — the old system's contract, kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('page_type', 30);               // PageType value
            $table->string('description', 255)->nullable();
            $table->boolean('is_default')->default(false); // the one offered first for its type
            $table->json('sections');                      // ordered list of {type, data}
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_templates');
    }
};
