<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Received invoices — the vendor's bill to the studio (slice I). A bill posts Dr the expense/asset accounts
 * on its lines and Cr Accounts payable 2010; paying it posts Dr 2010 and Cr cash or bank. The vendor's own
 * number is stored as typed, and the pair (vendor, number) is unique so the same bill cannot be entered twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('number', 60);
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->date('bill_date')->index();
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->unsignedBigInteger('paid_minor')->default(0);
            $table->string('notes', 500)->nullable();
            $table->string('attachment_path', 255)->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['vendor_id', 'number']);
        });

        Schema::create('bill_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->string('description', 255);
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_items');
        Schema::dropIfExists('bills');
    }
};
