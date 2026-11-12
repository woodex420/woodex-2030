<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('series_content_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('series_id')->constrained('commerce_series')->restrictOnDelete();
            $table->json('content');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
        });
        Schema::table('commerce_series', function (Blueprint $table): void {
            $table->unsignedInteger('content_version')->default(0);
            $table->foreignId('draft_revision_id')->nullable()->constrained('series_content_revisions')->restrictOnDelete();
            $table->foreignId('published_revision_id')->nullable()->constrained('series_content_revisions')->restrictOnDelete();
        });
        Schema::create('series_content_previews', function (Blueprint $table): void {
            $table->foreignId('revision_id')->constrained('series_content_revisions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('context_hash', 64);
            $table->timestamp('previewed_at');
            $table->primary(['revision_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('series_content_previews');
        Schema::table('commerce_series', function (Blueprint $table): void {
            $table->dropForeign(['draft_revision_id']);
            $table->dropForeign(['published_revision_id']);
            $table->dropColumn(['content_version', 'draft_revision_id', 'published_revision_id']);
        });
        Schema::dropIfExists('series_content_revisions');
    }
};
