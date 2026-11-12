<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_media_assets', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 30);
            $table->unsignedBigInteger('source_id');
            $table->string('title')->default('');
            $table->string('folder', 120);
            $table->timestamps();
            $table->unique(['source_type', 'source_id']);
        });
        Schema::create('master_media_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('master_media_assets')->restrictOnDelete();
            $table->string('sha256', 64);
            $table->string('path');
            $table->string('mime', 60);
            $table->unsignedInteger('bytes');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->timestamp('created_at');
            $table->unique(['asset_id', 'sha256']);
        });
        Schema::create('master_media_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 30);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('version_id')->constrained('master_media_versions')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['source_type', 'source_id']);
        });
        Schema::create('master_media_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('version_id')->constrained('master_media_versions')->restrictOnDelete();
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('revision_id')->index();
            $table->unsignedInteger('slot');
            $table->unique(['revision_id', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_media_usages');
        Schema::dropIfExists('master_media_sources');
        Schema::dropIfExists('master_media_versions');
        Schema::dropIfExists('master_media_assets');
    }
};
