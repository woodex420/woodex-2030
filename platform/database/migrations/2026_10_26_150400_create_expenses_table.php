<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-off expenses and other income, entered straight into the books (slice I). `direction` is `out` for
 * money spent and `in` for income that is not a client invoice; `paid_from` names the account the money
 * moved through (cash, bank, wallet) or `payable` when it is still owed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('direction', 8)->default('out')->index();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->string('description', 255);
            $table->unsignedBigInteger('amount_minor');
            $table->date('spent_on')->index();
            $table->string('paid_from', 20)->default('cash');
            $table->foreignId('payment_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->string('reference', 80)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('recurring_expense_id')->nullable()->constrained('recurring_expenses')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
