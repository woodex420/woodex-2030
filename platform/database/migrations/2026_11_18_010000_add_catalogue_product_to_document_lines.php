<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| A line chosen from the catalogue (T-086).
|
| A quotation line and an invoice line may name the product of the catalogue they were written from. The name, the
| code and the price on the line are the line's own copy, taken at that moment: the catalogue is never read again
| for it. So the link is only a link. A product that is ever removed leaves the line as it reads, without the link.
|
| This migration only adds. No existing line is changed; the way back takes the new columns away again.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_items', function (Blueprint $table): void {
            $table->foreignId('product_id')->nullable()->constrained('commerce_products')->nullOnDelete();
            // The product's code (SKU) as it was when the line was written.
            $table->string('product_code', 100)->nullable();
            // The catalogue had no price for it. Sending waits until somebody types the rate.
            $table->boolean('price_on_request')->default(false);
        });

        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->foreignId('product_id')->nullable()->constrained('commerce_products')->nullOnDelete();
            $table->string('product_code', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_id');
        });
        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->dropColumn('product_code');
        });

        Schema::table('quote_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_id');
        });
        Schema::table('quote_items', function (Blueprint $table): void {
            $table->dropColumn(['product_code', 'price_on_request']);
        });
    }
};
