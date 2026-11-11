<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AUT-001/AUT-005: the follow-up sequences engine. A sequence is ordered steps (wait → send a
 * mapped WhatsApp template → create a task → move the stage); a lead is enrolled by a trigger or
 * by hand; every step of every enrolment is a logged run, so "why did the client get that
 * message?" always has an answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('trigger');              // SequenceTrigger value
            $table->boolean('exits_on_reply')->default(true);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_builtin')->default(false); // seeded, but still editable (AUT-002)
            $table->timestamps();
        });

        Schema::create('automation_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sequence_id')->constrained('automation_sequences')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('kind');                 // StepKind value
            $table->unsignedInteger('wait_minutes')->nullable();     // wait: how long the previous step waits before this one runs
            $table->string('template_event')->nullable();            // send_template: the mapped moment, never a Meta name
            $table->string('task_title')->nullable();                // create_task
            $table->unsignedInteger('task_due_minutes')->nullable();
            $table->string('stage_key')->nullable();                 // change_stage: pipeline stage key
            $table->timestamps();
            $table->unique(['sequence_id', 'position']);
        });

        Schema::create('automation_enrolments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sequence_id')->constrained('automation_sequences')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->string('status')->default('active'); // EnrolmentStatus value
            $table->timestamp('enrolled_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason')->nullable();
            $table->timestamps();
            $table->index(['status', 'enrolled_at']);
        });

        Schema::create('automation_step_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enrolment_id')->constrained('automation_enrolments')->cascadeOnDelete();
            $table->foreignId('step_id')->constrained('automation_steps')->cascadeOnDelete();
            $table->string('status')->default('pending'); // RunStatus value
            $table->timestamp('scheduled_at')->index();
            $table->timestamp('ran_at')->nullable();
            $table->unsignedInteger('attempt')->default(0);
            $table->string('note')->nullable();   // "template follow_up sent as quote_followup_v2"
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_step_runs');
        Schema::dropIfExists('automation_enrolments');
        Schema::dropIfExists('automation_steps');
        Schema::dropIfExists('automation_sequences');
    }
};
