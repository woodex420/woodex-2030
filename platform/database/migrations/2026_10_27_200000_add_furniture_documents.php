<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['quote_items', 'invoice_items'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->string('product_name', 160)->nullable());
        }
        Schema::table('quotes', function (Blueprint $table): void {
            $table->bigInteger('shipping_minor')->default(0);
            $table->unsignedSmallInteger('advance_bp')->default(0);
        });
        Schema::table('invoices', function (Blueprint $table): void {
            $table->bigInteger('rent_minor')->default(0);
            $table->bigInteger('shipping_minor')->default(0);
            $table->unsignedInteger('tax_bp')->default(0);
            $table->unsignedSmallInteger('advance_bp')->default(0);
            $table->json('commercial')->nullable();
            $table->foreignId('source_quote_id')->nullable()->unique()->constrained('quotes')->restrictOnDelete();
        });
        Schema::table('document_designs', fn (Blueprint $table) => $table->json('furniture_style')->nullable());
        if (! DB::table('number_sequences')->where('key', 'furniture.invoice')->exists()) {
            DB::table('number_sequences')->insert(['key' => 'furniture.invoice', 'prefix' => 'WFI', 'format' => '{prefix}-{year}-{number}', 'next_number' => 1, 'padding' => 4, 'reset' => 'yearly', 'period' => now()->format('Y'), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('document_designs', fn (Blueprint $table) => $table->dropColumn('furniture_style'));
        // Drop the FK first: InnoDB may rely on the unique index as its only supporting key.
        Schema::table('invoices', fn (Blueprint $table) => $table->dropForeign(['source_quote_id']));
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique(['source_quote_id']);
            $table->dropColumn('source_quote_id');
            $table->dropColumn(['rent_minor', 'shipping_minor', 'tax_bp', 'advance_bp', 'commercial']);
        });
        Schema::table('quotes', fn (Blueprint $table) => $table->dropColumn(['shipping_minor', 'advance_bp']));
        foreach (['quote_items', 'invoice_items'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('product_name'));
        }
        // Never reset/delete a number sequence that may already have allocated live invoices.
    }
};
