<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_content_revisions', function (Blueprint $table): void {
            $table->id();
            $table->json('content');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
        });
        Schema::create('commerce_shop_state', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedInteger('version')->default(0);
            $table->foreignId('draft_revision_id')->nullable()->constrained('shop_content_revisions')->restrictOnDelete();
            $table->foreignId('published_revision_id')->nullable()->constrained('shop_content_revisions')->restrictOnDelete();
        });
        DB::table('commerce_shop_state')->insert(['id' => 1, 'version' => 0]);
        Schema::create('shop_content_previews', function (Blueprint $table): void {
            $table->foreignId('revision_id')->constrained('shop_content_revisions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('context_hash', 64);
            $table->timestamp('previewed_at');
            $table->primary(['revision_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_content_previews');
        Schema::dropIfExists('commerce_shop_state');
        Schema::dropIfExists('shop_content_revisions');
    }
};
