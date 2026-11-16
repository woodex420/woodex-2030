<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project costs reach the books (slice I). PRJ-009 recorded what a job cost on the project screen, but no
 * journal was ever created, so job profit and the P&L could not be true. These two columns hold the account
 * and the entry each cost was posted through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_expenses', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('amount_minor')->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->after('account_id')->constrained('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropConstrainedForeignId('account_id');
        });
    }
};
