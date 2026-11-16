<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Money paid out against a received invoice (slice I) — partial payments are the normal case. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_payments', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->date('paid_on')->index();
            $table->string('method', 20);
            $table->string('reference', 80)->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_payments');
    }
};
