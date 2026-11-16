<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table): void {
            // Explicit mapping, never assume Node and Laravel numeric IDs are equivalent.
            $table->string('storefront_product_id', 100)->nullable()->unique();
        });
        Schema::create('storefront_quote_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->string('payload_hash', 64);
            $table->string('reference')->unique();
            $table->foreignId('lead_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('pending_review');
            $table->json('lines');
            $table->bigInteger('estimated_total_minor');
            $table->string('currency', 3)->default('PKR');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_quote_requests');
        Schema::table('catalog_items', function (Blueprint $table): void {
            $table->dropUnique(['storefront_product_id']);
            $table->dropColumn('storefront_product_id');
        });
    }
};
