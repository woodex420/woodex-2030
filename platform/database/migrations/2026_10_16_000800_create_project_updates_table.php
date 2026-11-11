<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A note the team writes while the work happens, with the photographs that go with it (PRJ-004).
 * Only the ones marked client_visible leave the studio, and the moment they do is recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_milestone_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->unsignedTinyInteger('progress_percent')->nullable();
            $table->boolean('client_visible')->default(false);
            $table->timestamp('shared_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_updates');
    }
};
