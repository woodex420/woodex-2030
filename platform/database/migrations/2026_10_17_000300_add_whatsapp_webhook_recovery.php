<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->index(['provider', 'status', 'next_attempt_at'], 'webhook_recovery_index');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropIndex('webhook_recovery_index');
            $table->dropColumn(['queued_at', 'next_attempt_at']);
        });
    }
};
