<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_media', function (Blueprint $table): void {
            $table->string('storefront_key', 200)->nullable()->unique();
            $table->foreignId('storefront_version_id')->nullable()->constrained('master_media_versions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('website_media', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('storefront_version_id');
            $table->dropColumn('storefront_key');
        });
    }
};
