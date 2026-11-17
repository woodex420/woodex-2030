<?php

declare(strict_types=1);

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Enums\StageType;
use App\Domain\Crm\Events\LeadStageChanged;
use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\PipelineStage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pipeline move with business rules (CRM-004): lost needs a reason; won needs an accepted
 * quotation unless the owner overrides; every move is written to the timeline.
 */
final class MoveLeadToStage
{
    public function handle(Lead $lead, PipelineStage $to, ?User $by = null, ?string $lostReason = null, bool $ownerOverride = false): Lead
    {
        $from = $lead->stage;

        if ($from->is($to)) {
            return $lead;
        }

        if ($to->type === StageType::Lost && blank($lostReason)) {
            throw ValidationException::withMessages(['lost_reason' => 'Please choose why this lead was lost.']);
        }

        if ($to->type === StageType::Won && ! $ownerOverride && ! $lead->quotes()->where('status', 'accepted')->exists()) {
            throw ValidationException::withMessages(['stage' => 'A lead can be marked Won when a quotation is accepted (or by the owner).']);
        }

        return DB::transaction(function () use ($lead, $from, $to, $by, $lostReason): Lead {
            $lead->stage_id = $to->id;
            $lead->stage_changed_at = now();
            $lead->last_activity_at = now();

            // A person moving a lead past "New" has spoken to the client: that is the first response (CRM-010).
            if ($by !== null && $lead->first_response_at === null && $to->key !== 'new') {
                $lead->first_response_at = now();
            }

            if ($to->type === StageType::Lost) {
                $lead->lost_at = now();
                $lead->lost_reason = $lostReason;
            }

            if ($to->type === StageType::Won) {
                $lead->won_at = now();
            }

            if ($to->type === StageType::Open) {
                $lead->lost_at = null;
                $lead->lost_reason = null;
            }

            $lead->save();

            Activity::create([
                'contact_id' => $lead->contact_id,
                'lead_id' => $lead->id,
                'user_id' => $by?->id,
                'type' => ActivityType::StageChange,
                'body' => "Stage: {$from->name} → {$to->name}".($lostReason ? " ({$lostReason})" : ''),
                'data' => ['from' => $from->key, 'to' => $to->key, 'lost_reason' => $lostReason],
                'occurred_at' => now(),
            ]);

            event(new LeadStageChanged($lead, $from, $to, $by?->id));

            return $lead;
        });
    }
}
