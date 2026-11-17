<?php

declare(strict_types=1);

namespace App\Domain\Projects\Actions;

use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Models\Activity;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectMilestone;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A milestone reached on site (PRJ-003). Marking it stamps reached_at, lifts the project's progress to the
 * milestone's own percentage, and writes it on the client's timeline — the invoice it unlocks is raised on
 * purpose by the team (RaiseMilestoneInvoice), never by accident.
 */
final class ReachProjectMilestone
{
    public function handle(ProjectMilestone $milestone, User $by, bool $reached = true): ProjectMilestone
    {
        $milestone->loadMissing('project.contact');

        return DB::transaction(function () use ($milestone, $by, $reached): ProjectMilestone {
            $milestone->forceFill([
                'reached_at' => $reached ? now() : null,
            ])->save();

            $project = $milestone->project;

            // The plan's percentages add up to 100, so the ones reached are the percentage of the job done.
            $done = (int) $project->milestones()->whereNotNull('reached_at')->sum('percent_bp');

            $project->forceFill(['progress_percent' => max(0, min(100, intdiv($done, 100)))])->save();

            Activity::create([
                'contact_id' => $project->contact_id,
                'lead_id' => $project->lead_id,
                'user_id' => $by->id,
                'type' => ActivityType::Note,
                'body' => sprintf(
                    '%s: %s %s.',
                    $project->code,
                    $milestone->name,
                    $reached ? 'reached' : 'reopened',
                ),
                'data' => ['project_id' => $project->id, 'milestone_id' => $milestone->id],
                'occurred_at' => now(),
            ]);

            return $milestone;
        });
    }
}
