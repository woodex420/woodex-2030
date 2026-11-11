<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting foundation (docs/18 §2, batch C — P8.1): the chart of accounts, accounting periods and
 * a balanced, immutable journal. Posted entries are never edited — corrections are reversals.
 * All amounts are integer paisa, exactly like the rest of the system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 12)->unique();
            $table->string('name', 120);
            $table->string('type', 12)->index(); // asset | liability | equity | income | expense
            $table->foreignId('parent_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->string('business_line', 20)->nullable(); // interior | furniture | null = both
            $table->boolean('is_group')->default(false); // groups organise; only leaves accept postings
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('accounting_periods', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 60);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['starts_on', 'ends_on']);
        });

        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('entry_no', 24)->unique();
            $table->string('source_type', 40)->nullable(); // invoice, payment, expense … business origin
            $table->unsignedBigInteger('source_id')->nullable();
            $table->date('business_date')->index();
            $table->string('reference', 120)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->string('business_line', 20)->nullable();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('reverses_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('reversed_by_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();

            // One posting per business source: a retried verification or invoice action can never double-post.
            $table->unique(['source_type', 'source_id']);
        });

        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts');
            $table->bigInteger('debit_minor')->default(0);
            $table->bigInteger('credit_minor')->default(0);
            $table->string('memo', 160)->nullable();
            $table->timestamps();
            $table->index(['account_id', 'journal_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('chart_of_accounts');
    }
};
