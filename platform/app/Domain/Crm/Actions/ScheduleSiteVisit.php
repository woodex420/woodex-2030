<?php

declare(strict_types=1);

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Enums\NextActionType;
use App\Domain\Crm\Enums\VisitStatus;
use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\PipelineStage;
use App\Domain\Crm\Models\SiteVisit;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Book a site visit from a lead (CRM-008): the visit becomes the lead's next action and early-stage
 * leads move to "Site Visit".
 */
final class ScheduleSiteVisit
{
    /** @var list<string> */
    private const ADVANCE_FROM = ['new', 'contacted'];

    public function __construct(private readonly MoveLeadToStage $moveLeadToStage) {}

    public function handle(
        Lead $lead,
        CarbonInterface $at,
        int $durationMinutes = 60,
        ?string $address = null,
        ?User $assignee = null,
        ?User $by = null,
    ): SiteVisit {
        return DB::transaction(function () use ($lead, $at, $durationMinutes, $address, $assignee, $by): SiteVisit {
            $assignee ??= $lead->owner ?? $by;

            $visit = SiteVisit::create([
                'lead_id' => $lead->id,
                'contact_id' => $lead->contact_id,
                'scheduled_at' => $at,
                'duration_minutes' => $durationMinutes,
                'address' => $address ?: ($lead->address ?? trim(($lead->area ?? '').', '.($lead->city ?? ''), ', ')),
                'assigned_to' => $assignee?->id,
                'status' => VisitStatus::Scheduled,
            ]);

            $lead->forceFill([
                'next_action_type' => NextActionType::Visit,
                'next_action_at' => $at,
                'last_activity_at' => now(),
            ])->save();

            Activity::create([
                'contact_id' => $lead->contact_id,
                'lead_id' => $lead->id,
                'user_id' => $by?->id,
                'subject_type' => 'site_visit',
                'subject_id' => $visit->id,
                'type' => ActivityType::Visit,
                'body' => 'Site visit scheduled for '.$at->timezone('Asia/Karachi')->format('D j M, g:i a')
                    .($assignee ? " with {$assignee->name}" : ''),
                'data' => ['visit_id' => $visit->id, 'status' => VisitStatus::Scheduled->value],
                'occurred_at' => now(),
            ]);

            if (in_array($lead->stage()->value('key'), self::ADVANCE_FROM, true)) {
                $this->moveLeadToStage->handle($lead->refresh(), PipelineStage::byKey('site_visit'), $by);
            }

            return $visit;
        });
    }
}
