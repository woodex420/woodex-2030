<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The public website as content, not code (docs/09 §2.1, D-67): pages, their sections, media, the 301 map of the
 * 142 legacy URLs, menus and a version history for one-click rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_media', function (Blueprint $table): void {
            $table->id();
            $table->string('disk', 40)->default('public');
            $table->string('path', 255);
            $table->string('alt', 255)->default('');
            $table->string('title', 190)->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('folder', 80)->nullable()->index();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['disk', 'path']);
        });

        Schema::create('pages', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 30)->index();
            $table->string('slug', 190)->unique(); // with its trailing slash: "/", "/interior-design/", "/lahore/dha/"
            $table->foreignId('parent_id')->nullable()->constrained('pages')->cascadeOnDelete();
            $table->string('title', 190);
            $table->text('excerpt')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->string('template', 40)->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('seo')->nullable(); // title, description, canonical, og_image_id, robots, focus_keyword, last_reviewed
            $table->string('schema_type', 40)->nullable();
            $table->foreignId('featured_image_id')->nullable()->constrained('website_media')->nullOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'status']);
            $table->index(['parent_id', 'sort']);
        });

        Schema::create('page_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
            $table->string('type', 30);
            $table->unsignedSmallInteger('position')->default(0);
            $table->json('data')->nullable();
            $table->boolean('visible')->default(true);
            $table->timestamps();

            $table->index(['page_id', 'position']);
        });

        Schema::create('redirects', function (Blueprint $table): void {
            $table->id();
            $table->string('from_path', 190)->unique();
            $table->string('to_path', 500);
            $table->unsignedSmallInteger('code')->default(301);
            $table->unsignedInteger('hits')->default(0);
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('navigation_items', function (Blueprint $table): void {
            $table->id();
            $table->string('menu', 40)->index();
            $table->string('label', 120);
            $table->string('url', 500);
            $table->foreignId('parent_id')->nullable()->constrained('navigation_items')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('target', 10)->default('_self');
            $table->timestamps();
        });

        Schema::create('page_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
            $table->json('data'); // title, excerpt, seo + every block, so a page can be restored in one click
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_versions');
        Schema::dropIfExists('navigation_items');
        Schema::dropIfExists('redirects');
        Schema::dropIfExists('page_blocks');
        Schema::dropIfExists('pages');
        Schema::dropIfExists('website_media');
    }
};
