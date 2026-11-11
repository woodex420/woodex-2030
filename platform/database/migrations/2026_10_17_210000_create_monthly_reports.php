<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P8.5 monthly CRM report: the saved copy of what the owner was sent on the 1st, so the archive
 * under System → Automations shows the same numbers the Telegram message carried — the screen
 * never claims a month it did not compute. One row per month (period_start is unique).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Resumable on MySQL (non-transactional DDL) — see the P8.4 production incident.
        if (Schema::hasTable('monthly_reports')) {
            return;
        }

        Schema::create('monthly_reports', function (Blueprint $table): void {
            $table->id();
            $table->date('period_start')->unique(); // first day of the reported month
            $table->date('period_end'); // last day of the reported month
            $table->json('payload'); // the computed report, exactly as sent
            $table->timestamp('notified_at')->nullable(); // when the owner was last sent this month
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_reports');
    }
};
