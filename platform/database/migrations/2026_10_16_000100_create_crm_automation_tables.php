<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1 part 2 (docs/04 §4.9–4.11): assignment rules (CRM-009), saved list views (GLB-003),
 * recurring tasks + reminders (CRM-007) and the second SLA escalation level (CRM-010).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            // {"business_lines":[],"services":[],"cities":[],"sources":[]}; empty list = any
            $table->json('conditions')->nullable();
            $table->string('strategy', 20)->default('round_robin');
            $table->string('role', 30)->nullable();
            $table->json('user_ids')->nullable();
            $table->unsignedSmallInteger('max_open_leads')->nullable();
            $table->boolean('working_hours_only')->default(false);
            $table->foreignId('last_assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['is_active', 'position']);
        });

        Schema::create('saved_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('screen', 40);
            $table->string('name', 60);
            $table->json('filters');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'screen', 'name']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->string('recurrence', 10)->nullable()->after('remind_at');
            $table->timestamp('reminded_at')->nullable()->after('recurrence');
            $table->index(['status', 'remind_at']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('sla_escalated_at')->nullable()->after('sla_breached_at');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('sla_escalated_at');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['status', 'remind_at']);
            $table->dropColumn(['recurrence', 'reminded_at']);
        });

        Schema::dropIfExists('saved_views');
        Schema::dropIfExists('assignment_rules');
    }
};
