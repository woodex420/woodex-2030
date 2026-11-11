<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The handover checklist (PRJ-007): what the studio promises to leave behind, ticked off one by one, and shown
 * to the client who signs for it. The list a new handover starts from lives in Settings -> Sales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->timestamp('handover_started_at')->nullable()->after('completed_at');
            $table->timestamp('handover_completed_at')->nullable()->after('handover_started_at');
            $table->timestamp('client_ok_at')->nullable()->after('handover_completed_at');
            $table->string('client_ok_name', 120)->nullable()->after('client_ok_at');
            $table->timestamp('review_requested_at')->nullable()->after('client_ok_name');
            $table->boolean('case_study_consent')->default(false)->after('review_requested_at');
            $table->foreignId('case_study_page_id')->nullable()->after('case_study_consent')->constrained('pages')->nullOnDelete();
        });

        Schema::create('project_handover_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('label', 200);
            $table->text('note')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['project_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_handover_items');

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropForeign(['case_study_page_id']);
            $table->dropColumn([
                'handover_started_at', 'handover_completed_at', 'client_ok_at', 'client_ok_name',
                'review_requested_at', 'case_study_consent', 'case_study_page_id',
            ]);
        });
    }
};
