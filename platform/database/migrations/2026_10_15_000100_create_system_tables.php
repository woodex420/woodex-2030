<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System tables: numbering, webhooks, integration logs, Telegram, alert rules,
 * approvals, change requests and legacy import references (docs/04 §3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('prefix', 20);
            $table->unsignedBigInteger('next_number');
            $table->unsignedTinyInteger('padding')->default(0);
            $table->string('format', 60)->default('{prefix}-{number}');
            $table->string('reset', 10)->default('never');
            $table->string('period', 10)->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('external_id', 191);
            $table->string('type', 80)->nullable();
            $table->json('payload');
            $table->boolean('signature_valid')->default(false);
            $table->string('status', 20)->default('received')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
        });

        Schema::create('integration_logs', function (Blueprint $table) {
            $table->id();
            $table->string('integration', 30);
            $table->string('direction', 3)->default('out');
            $table->string('action', 80);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->boolean('ok')->default(false);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->text('error')->nullable();
            $table->nullableMorphs('subject');
            $table->timestamp('created_at')->nullable();

            $table->index(['integration', 'created_at']);
        });

        Schema::create('telegram_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('chat_id')->nullable()->unique();
            $table->string('chat_type', 20)->nullable();
            $table->string('title', 120)->nullable();
            $table->string('username', 64)->nullable();
            $table->string('link_code', 16)->nullable()->unique();
            $table->timestamp('link_code_expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('muted_until')->nullable();
            $table->json('preferences')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->string('event', 80)->index();
            $table->string('name', 120);
            $table->json('conditions')->nullable();
            $table->json('recipients');
            $table->json('channels');
            $table->boolean('respect_quiet_hours')->default(true);
            $table->string('priority', 10)->default('normal');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->morphs('approvable');
            $table->string('type', 30);
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('reason')->nullable();
            $table->json('payload')->nullable();
            $table->string('channel', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('change_requests', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->default('idea');
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->string('priority', 10)->default('normal');
            $table->string('status', 20)->default('new')->index();
            $table->string('phase', 10)->nullable();
            $table->string('source', 20)->default('admin');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->bigInteger('telegram_message_id')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('legacy_refs', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_table', 60);
            $table->string('legacy_id', 64);
            $table->string('model_type', 60);
            $table->unsignedBigInteger('model_id');
            $table->string('checksum', 64)->nullable();
            $table->timestamp('imported_at')->nullable();

            $table->unique(['legacy_table', 'legacy_id']);
            $table->index(['model_type', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_refs');
        Schema::dropIfExists('change_requests');
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('alert_rules');
        Schema::dropIfExists('telegram_links');
        Schema::dropIfExists('integration_logs');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('number_sequences');
    }
};
