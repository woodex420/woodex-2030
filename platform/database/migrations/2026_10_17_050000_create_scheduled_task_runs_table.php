<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * SYS-008 (S5): every scheduled task's last run, written by a listener on ScheduledTaskFinished.
 * The Health screen and /health show a task that stopped running, which a heartbeat alone cannot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_task_runs', function (Blueprint $table) {
            $table->id();
            $table->string('task', 191);
            $table->timestamp('ran_at');
            $table->unsignedInteger('runtime_ms')->nullable();
            $table->boolean('ok')->default(true);
            $table->index(['task', 'ran_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_runs');
    }
};
