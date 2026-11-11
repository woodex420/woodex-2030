<?php

declare(strict_types=1);

namespace App\Domain\Projects\Actions;

use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Models\Activity;
use App\Domain\Projects\Enums\ProjectStage;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Mail\ProjectMail;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Notifications\ProjectNotification;
use App\Domain\System\Alerts\AlertRouter;
use App\Models\User;
use App\Settings\SalesSettings;
use Illuminate\Support\Facades\Mail;

/**
 * Moving a project along its stages (PRJ-002): planning → design → procurement → execution → finishing →
 * handover → completed. The stage is what the client is told; the status is whether the job is alive.
 *
 * Reaching "completed" closes the job: progress 100%, status completed, completed_at set. A stage change is
 * written on the client's timeline, and — while Settings allow it — the client is told in one line what the
 * stage means for them (a template per stage, in the studio's own words).
 */
final class ChangeProjectStage
{
    public function __construct(private readonly SalesSettings $sales) {}

    public function handle(Project $project, ProjectStage $stage, User $by, bool $notifyClient = true): Project
    {
        $from = $project->stage;
        $project->loadMissing('contact');

        $completed = $stage === ProjectStage::Completed;

        $project->forceFill([
            'stage' => $stage,
            'status' => $completed ? ProjectStatus::Completed : $this->statusFor($project, $stage),
            'progress_percent' => $completed ? 100 : $project->progress_percent,
            'completed_at' => $completed ? now() : null,
        ])->save();

        Activity::create([
            'contact_id' => $project->contact_id,
            'lead_id' => $project->lead_id,
            'user_id' => $by->id,
            'type' => ActivityType::StageChange,
            'body' => sprintf('%s: %s → %s.', $project->code, $from->label(), $stage->label()),
            'data' => ['project_id' => $project->id, 'from' => $from->value, 'to' => $stage->value],
            'occurred_at' => now(),
        ]);

        app(AlertRouter::class)->send('project.stage',
            User::query()->with('telegramLink')->permission('projects.view')->where('is_active', true)->get(),
            new ProjectNotification(
                title: $project->code.': '.$from->label().' → '.$stage->label(),
                body: $project->name,
                url: route('admin.projects.show', $project),
                kind: 'stage',
            ),
        );

        if ($notifyClient
            && $this->sales->project_stage_notifies_client
            && $project->stage !== $from
            && $project->contact?->email !== null
            && $project->contact->email !== ''
        ) {
            Mail::to($project->contact->email)->queue(new ProjectMail(
                projectId: $project->id,
                heading: $project->code.' is now at '.$stage->label(),
                body: $stage->clientLine(),
                buttonLabel: null,
                url: null,
            ));
        }

        return $project;
    }

    private function statusFor(Project $project, ProjectStage $stage): ProjectStatus
    {
        // A completed project stays completed; the others keep the status the team set (on hold survives a move).
        return $project->status === ProjectStatus::Completed
            ? ($stage === ProjectStage::Completed ? ProjectStatus::Completed : ProjectStatus::Active)
            : $project->status;
    }
}
