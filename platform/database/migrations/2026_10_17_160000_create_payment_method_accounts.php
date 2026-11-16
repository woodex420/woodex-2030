<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P8.2: each payment method maps to the ledger account its money lands in.
 * Seeded with defaults; the owner adjusts them on Accounts → Payment methods.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL is not transactional: an interrupted first attempt (timeout, aborted request,
        // two clicks racing) can leave the table created but the migrations row unwritten, and the
        // retry then fatals on "table already exists" — the exact state this guard resumes from.
        // The table can only have been created by this migration's own DDL, so its shape is right.
        if (Schema::hasTable('payment_method_accounts')) {
            return;
        }

        Schema::create('payment_method_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('method', 40)->unique();
            $table->unsignedBigInteger('account_id');
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('chart_of_accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_method_accounts');
    }
};
