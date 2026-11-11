<?php

declare(strict_types=1);

namespace App\Domain\Projects\Actions;

use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Models\Activity;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectMilestone;
use App\Domain\Projects\Models\ProjectUpdate;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A post in the site diary (PRJ-004): what happened on site today, with the photographs, written from a phone.
 *
 * Everything posted here is internal until somebody shares it: the team's own notes, snags and prices stay in the
 * studio, and only `ShareProjectUpdate` puts a message in the client's hands.
 */
final class PostProjectUpdate
{
    public function __construct(private readonly ShareProjectUpdate $share) {}

    /**
     * @param  array{body: string, progress_percent?: int|null, project_milestone_id?: int|null}  $input
     * @param  list<UploadedFile>  $photos
     */
    public function handle(Project $project, User $by, array $input, array $photos = [], bool $shareWithClient = false): ProjectUpdate
    {
        return DB::transaction(function () use ($project, $by, $input, $photos, $shareWithClient): ProjectUpdate {
            $update = ProjectUpdate::query()->create([
                'project_id' => $project->id,
                'user_id' => $by->id,
                'project_milestone_id' => $input['project_milestone_id'] ?? null,
                'body' => trim($input['body']),
                'progress_percent' => $input['progress_percent'] ?? null,
                'client_visible' => false,
            ]);

            foreach ($photos as $photo) {
                $update->addMedia($photo->getRealPath())
                    ->usingFileName($photo->hashName())
                    ->usingName(pathinfo($photo->getClientOriginalName(), PATHINFO_FILENAME))
                    ->withCustomProperties([
                        'uploaded_by' => $by->id,
                        'original_name' => $photo->getClientOriginalName(),
                    ])
                    ->toMediaCollection('photos');
            }

            // A posted percentage is the PM's word on where the job stands.
            $percent = $input['progress_percent'] ?? null;

            if ($percent !== null && $project->progress_percent !== (int) $percent) {
                $project->forceFill(['progress_percent' => max(0, min(100, (int) $percent))])->save();
            }

            if ($input['project_milestone_id'] ?? false) {
                /** @var ProjectMilestone|null $milestone */
                $milestone = $project->milestones()->find($input['project_milestone_id']);

                if ($milestone !== null && $milestone->reached_at === null) {
                    app(ReachProjectMilestone::class)->handle($milestone, $by);
                }
            }

            Activity::create([
                'contact_id' => $project->contact_id,
                'lead_id' => $project->lead_id,
                'user_id' => $by->id,
                'type' => ActivityType::Note,
                'body' => sprintf('%s: %s', $project->code, Str::limit($update->body, 160)),
                'data' => ['project_id' => $project->id, 'update_id' => $update->id, 'shared' => $shareWithClient],
                'occurred_at' => now(),
            ]);

            if ($shareWithClient) {
                $this->share->handle($update);
            }

            return $update;
        });
    }
}
