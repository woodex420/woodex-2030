<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repeating overheads — rent, salaries, subscriptions (slice I). The same shape as recurring invoices:
 * the scheduler reads next_run_on, raises one expense, posts it and moves the date forward from itself
 * (never from "today"), so a missed run cannot drift the series.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->string('description', 160);
            $table->unsignedBigInteger('amount_minor');
            $table->string('paid_from', 20)->default('bank');
            $table->string('frequency', 20)->default('monthly');
            $table->date('starts_on');
            $table->date('next_run_on')->index();
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('raised_count')->default(0);
            $table->timestamp('last_raised_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_expenses');
    }
};
