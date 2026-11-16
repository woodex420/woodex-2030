<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nullable: do not infer projects or repost any existing financial history.
        Schema::table('bills', function (Blueprint $table): void {
            $table->foreignId('project_id')->nullable()->index()->constrained('projects')->restrictOnDelete();
        });
        Schema::table('expenses', function (Blueprint $table): void {
            $table->foreignId('project_id')->nullable()->index()->constrained('projects')->restrictOnDelete();
        });
        Schema::table('bill_payments', function (Blueprint $table): void {
            $table->uuid('idempotency_key')->nullable()->unique();
        });
        // Request identity only, NOT another ledger: amounts live exclusively in bills/expenses.
        Schema::create('project_cost_requests', function (Blueprint $table): void {
            $table->uuid('request_key')->primary();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->char('fingerprint', 64);
            $table->foreignId('bill_id')->nullable()->unique()->constrained('bills')->restrictOnDelete();
            $table->foreignId('expense_id')->nullable()->unique()->constrained('expenses')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_cost_requests');
        Schema::table('bill_payments', fn (Blueprint $table) => $table->dropUnique(['idempotency_key']));
        Schema::table('bill_payments', fn (Blueprint $table) => $table->dropColumn('idempotency_key'));
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropForeign(['project_id']);
            $table->dropIndex(['project_id']);
            $table->dropColumn('project_id');
        });
        Schema::table('bills', function (Blueprint $table): void {
            $table->dropForeign(['project_id']);
            $table->dropIndex(['project_id']);
            $table->dropColumn('project_id');
        });
    }
};
