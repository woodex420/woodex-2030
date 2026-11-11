<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendors — the people the studio buys from (slice I). Until now every table in the OS faced the client;
 * the money-out side had nowhere to record who was paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name', 160);
            $table->string('phone', 20)->nullable()->index();
            $table->string('email', 160)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('tax_number', 40)->nullable();          // NTN / STRN as printed on the bill
            $table->unsignedSmallInteger('payment_terms_days')->default(0);
            $table->foreignId('expense_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
