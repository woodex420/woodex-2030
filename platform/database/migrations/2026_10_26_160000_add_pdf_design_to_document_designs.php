<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_designs', function (Blueprint $table): void {
            $table->string('pdf_theme', 20)->default('classic');
            $table->json('quote_blocks')->nullable();
            $table->json('invoice_blocks')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('document_designs', function (Blueprint $table): void {
            $table->dropColumn(['pdf_theme', 'quote_blocks', 'invoice_blocks']);
        });
    }
};
