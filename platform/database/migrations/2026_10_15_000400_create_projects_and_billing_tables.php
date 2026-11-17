<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projects and billing: bank accounts, projects, milestones, invoices, payments, allocations (docs/04 §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('business_line', 20)->nullable();
            $table->string('kind', 10)->default('bank');
            $table->string('title', 160);
            $table->string('bank_name', 120);
            $table->string('account_number', 60)->nullable();
            $table->string('iban', 40)->nullable();
            $table->string('branch', 160)->nullable();
            $table->string('branch_code', 20)->nullable();
            $table->string('wallet_provider', 20)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('code', 30)->unique();
            $table->string('name', 200);
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quote_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('business_line', 20);
            $table->string('site_address', 255)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('area', 120)->nullable();
            $table->foreignId('pm_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('stage', 20)->default('planning');
            $table->string('status', 20)->default('active')->index();
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->date('start_date')->nullable();
            $table->date('target_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->bigInteger('value_minor')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('project_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_milestone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160);
            $table->unsignedSmallInteger('percent_bp')->nullable();
            $table->bigInteger('amount_minor')->default(0);
            $table->string('trigger', 40)->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('reached_at')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('number', 40)->unique();
            $table->string('kind', 20)->default('milestone');
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_milestone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('business_line', 20);
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->char('currency', 3)->default('PKR');
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->bigInteger('paid_minor')->default(0);
            $table->bigInteger('balance_minor')->default(0);
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->json('snapshot')->nullable();
            $table->string('pdf_path', 255)->nullable();
            $table->char('pdf_sha256', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->text('description');
            $table->string('unit', 20)->nullable();
            $table->decimal('qty', 14, 3)->default(1);
            $table->bigInteger('rate_minor')->default(0);
            $table->bigInteger('discount_minor')->default(0);
            $table->unsignedInteger('tax_bp')->default(0);
            $table->bigInteger('line_total_minor')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('amount_minor');
            $table->date('received_on');
            $table->string('method', 20);
            $table->string('reference', 120)->nullable();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 25)->default('pending_verification')->index();
            $table->string('submitted_by', 10)->default('staff');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->string('receipt_number', 30)->nullable()->unique();
            $table->string('receipt_pdf_path', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->timestamps();

            $table->unique(['payment_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('project_milestones');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('bank_accounts');
    }
};
