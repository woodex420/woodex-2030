<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3: the invoice a payment came in against (INV-006).
 *
 * A client uploads a proof from one invoice's page, and that invoice is the one the money is meant for. The
 * allocations still say where the money actually went — a payment can cover more than one invoice, or part of one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('invoice_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('invoice_id');
        });
    }
};
