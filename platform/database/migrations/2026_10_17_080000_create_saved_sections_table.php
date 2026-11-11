<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * V2-2 (docs/12 §5.2): saved sections — a section configured once in the page editor, kept by name and
 * reusable on any page. The library's "saved by the studio" shelf; the page data itself stays in page_blocks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_sections', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('type', 30);                 // BlockType value
            $table->json('data');                       // the section's fields, as configured
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_sections');
    }
};
