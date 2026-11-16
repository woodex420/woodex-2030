<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P8.5 web chat widget: the visitor's half of a web_chat conversation. The token is the widget's
 * only credential — it lives in the visitor's localStorage and resumes their thread on return.
 * widget_state carries the rule-based intake (asked_name → asked_number → asked_service|contact
 * → live); after handover the team replies from the inbox and the widget polls for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Resumable on MySQL (non-transactional DDL) — see the P8.4 production incident.
        if (! Schema::hasTable('conversations')) {
            return;
        }

        if (! Schema::hasColumn('conversations', 'widget_token')) {
            Schema::table('conversations', function (Blueprint $table): void {
                $table->string('widget_token', 48)->nullable()->unique();
            });
        }

        if (! Schema::hasColumn('conversations', 'widget_state')) {
            Schema::table('conversations', function (Blueprint $table): void {
                $table->string('widget_state', 120)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('conversations')) {
            return;
        }

        if (Schema::hasColumn('conversations', 'widget_token')) {
            Schema::table('conversations', function (Blueprint $table): void {
                $table->dropUnique(['widget_token']);
                $table->dropColumn('widget_token');
            });
        }

        if (Schema::hasColumn('conversations', 'widget_state')) {
            Schema::table('conversations', function (Blueprint $table): void {
                $table->dropColumn('widget_state');
            });
        }
    }
};
