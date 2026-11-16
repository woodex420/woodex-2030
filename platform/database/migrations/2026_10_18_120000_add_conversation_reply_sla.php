<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CNV-007: reply SLA on WhatsApp conversations. Every inbound message arms a reply-due
 * timestamp (first reply gets the longer target, later ones the shorter); a staff reply
 * clears it. The existing woodex:sla-check command flags the breaches — mirroring the
 * lead SLA, escalation included.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->timestamp('reply_due_at')->nullable()->index();
            $table->timestamp('reply_sla_breached_at')->nullable();
            $table->timestamp('reply_sla_escalated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn(['reply_due_at', 'reply_sla_breached_at', 'reply_sla_escalated_at']);
        });
    }
};
