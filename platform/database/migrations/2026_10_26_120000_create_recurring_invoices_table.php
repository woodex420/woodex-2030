<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring invoices (slice E, part 2): a client who pays the same amount every month — a retainer, a
 * maintenance contract — gets an invoice raised by the scheduler instead of by memory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_invoices', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 160);
            $table->string('business_line', 20);
            $table->string('frequency', 20)->default('monthly');
            $table->json('lines');
            $table->unsignedSmallInteger('tax_bp')->default(0);
            $table->unsignedSmallInteger('due_days')->nullable();
            $table->string('notes', 500)->nullable();
            $table->date('starts_on');
            $table->date('next_issue_on')->index();
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('invoice_count')->default(0);
            $table->timestamp('last_issued_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_invoices');
    }
};
