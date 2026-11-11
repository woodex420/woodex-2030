<?php

declare(strict_types=1);

namespace App\Domain\Projects\Actions;

use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Models\Activity;
use App\Domain\Projects\Enums\ProjectStage;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The client signing for their space (PRJ-007): they read the checklist on the portal, tick the box and type
 * their name. That name and the moment are what the studio keeps; a signature is a name typed in public.
 *
 * The job is only handed over when every line is ticked — otherwise the client would be signing for work that
 * is not finished, and the studio for work it has not done.
 */
final class AcceptHandover
{
    public function handle(Project $project, string $name, ?User $by = null): Project
    {
        [$done, $total] = $project->handoverProgress();

        if ($total === 0 || $done < $total) {
            throw ValidationException::withMessages([
                'confirm' => $total === 0
                    ? 'The handover checklist has not started yet.'
                    : sprintf('%d of %d items are still to be finished.', $total - $done, $total),
            ]);
        }

        $project->forceFill([
            'client_ok_at' => now(),
            'client_ok_name' => trim($name),
            'handover_completed_at' => $project->handover_completed_at ?? now(),
            'stage' => ProjectStage::Completed,
            'status' => ProjectStatus::Completed,
            'completed_at' => $project->completed_at ?? now(),
            'progress_percent' => 100,
        ])->save();

        Activity::create([
            'contact_id' => $project->contact_id,
            'lead_id' => $project->lead_id,
            'user_id' => $by?->id,
            'type' => ActivityType::StageChange,
            'body' => sprintf('%s: handed over and signed for by %s.', $project->code, trim($name)),
            'data' => ['project_id' => $project->id, 'handover' => true],
            'occurred_at' => now(),
        ]);

        return $project->refresh();
    }
}
