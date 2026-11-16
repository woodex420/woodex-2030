<?php

declare(strict_types=1);

namespace App\Domain\Projects\Actions;

use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Models\Activity;
use App\Domain\Projects\Models\Project;
use App\Settings\SalesSettings;
use Illuminate\Support\Facades\DB;

/**
 * Opening the handover checklist (PRJ-007): Settings -> Sales holds the lines the studio always promises
 * ("keys handed over", "paint touched up"), and they are copied onto the job so this project can add its own.
 *
 * Called as often as the button is pressed: lines already there are never duplicated.
 */
final class StartHandover
{
    public function __construct(private readonly SalesSettings $sales) {}

    public function handle(Project $project, bool $reset = false): Project
    {
        return DB::transaction(function () use ($project, $reset): Project {
            if ($reset) {
                $project->handoverItems()->delete();
            }

            $position = (int) $project->handoverItems()->max('position');

            foreach ($this->sales->handover_checklist as $label) {
                $label = trim((string) $label);

                if ($label === '' || $project->handoverItems()->where('label', $label)->exists()) {
                    continue;
                }

                $project->handoverItems()->create([
                    'label' => $label,
                    'position' => ++$position,
                ]);
            }

            $project->forceFill(['handover_started_at' => $project->handover_started_at ?? now()])->save();

            Activity::create([
                'contact_id' => $project->contact_id,
                'lead_id' => $project->lead_id,
                'type' => ActivityType::Note,
                'body' => sprintf('%s: handover checklist started (%d items).', $project->code, $project->handoverItems()->count()),
                'data' => ['project_id' => $project->id],
                'occurred_at' => now(),
            ]);

            return $project->refresh();
        });
    }
}
