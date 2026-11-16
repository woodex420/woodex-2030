<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_products', function (Blueprint $table): void {
            $table->id();
            $table->string('source_key', 140)->nullable()->unique();
            $table->string('slug', 140)->unique();
            $table->json('source_record')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->string('name', 160);
            $table->json('draft');
            $table->json('review_issues')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->unsignedBigInteger('published_revision_id')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::create('commerce_product_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('commerce_products')->cascadeOnDelete();
            $table->string('path');
            $table->string('sha256', 64);
            $table->timestamps();
        });
        Schema::create('commerce_product_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('commerce_products')->cascadeOnDelete();
            $table->json('content');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
        });
        Schema::create('commerce_product_previews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('commerce_products')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('fingerprint', 64);
            $table->timestamp('previewed_at');
            $table->unique(['product_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_product_previews');
        Schema::dropIfExists('commerce_product_revisions');
        Schema::dropIfExists('commerce_product_media');
        Schema::dropIfExists('commerce_products');
    }
};
