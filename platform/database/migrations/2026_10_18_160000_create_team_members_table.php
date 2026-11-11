<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2-3 F7 (docs/14 §5a): the studio's team — members with a photo, a role and a short bio, managed
 * once and shown on any page by its Team section. The library contract (`LibraryEntry`) is what a
 * section sees; this table is where the members live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_members', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('role', 120);
            $table->text('bio')->nullable();
            $table->foreignId('media_id')->nullable()->constrained('website_media')->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_members');
    }
};
