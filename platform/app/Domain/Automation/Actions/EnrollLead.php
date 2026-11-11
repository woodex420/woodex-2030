<?php

declare(strict_types=1);

namespace App\Domain\Automation\Actions;

use App\Domain\Automation\Enums\EnrolmentStatus;
use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Automation\Enums\SequenceTrigger;
use App\Domain\Automation\Enums\StepKind;
use App\Domain\Automation\Models\AutomationEnrolment;
use App\Domain\Automation\Models\AutomationSequence;
use App\Domain\Automation\Models\AutomationStepRun;
use App\Domain\Crm\Models\Lead;
use Carbon\CarbonInterface;

/**
 * Put a lead into every active sequence its trigger fires (AUT-001). A lead already running a
 * sequence is left alone — enrolment is once per sequence per lead; re-entering after an exit
 * (a nurture sequence, say) is a fresh decision, made by hand or by a new trigger.
 */
final class EnrollLead
{
    public function forTrigger(SequenceTrigger $trigger, Lead $lead): int
    {
        $count = 0;

        foreach (AutomationSequence::forTrigger($trigger) as $sequence) {
            $count += $this->inSequence($sequence, $lead) ? 1 : 0;
        }

        return $count;
    }

    public function inSequence(AutomationSequence $sequence, Lead $lead): ?AutomationEnrolment
    {
        if (! $sequence->is_active || $lead->contact_id === null) {
            return null;
        }

        $already = AutomationEnrolment::query()
            ->where('sequence_id', $sequence->id)
            ->where('lead_id', $lead->id)
            ->whereIn('status', [EnrolmentStatus::Active->value, EnrolmentStatus::Paused->value])
            ->first();

        if ($already !== null) {
            return $already;
        }

        $enrolment = AutomationEnrolment::create([
            'sequence_id' => $sequence->id,
            'lead_id' => $lead->id,
            'status' => EnrolmentStatus::Active,
            'enrolled_at' => now(),
        ]);

        $this->scheduleNext($enrolment);

        return $enrolment;
    }

    /**
     * Queue the step after the last run one — or the first step at enrolment. A leading wait
     * delays the step that follows it; an action step runs at its scheduled moment.
     */
    public function scheduleNext(AutomationEnrolment $enrolment, ?int $afterPosition = null, ?CarbonInterface $from = null): void
    {
        $steps = $enrolment->sequence->steps()->orderBy('position')->get();

        if ($steps->isEmpty()) {
            $enrolment->end(EnrolmentStatus::Completed, 'The sequence has no steps.');

            return;
        }

        $next = $afterPosition === null
            ? $steps->first()
            : $steps->firstWhere('position', '>', $afterPosition);

        if ($next === null) {
            $enrolment->end(EnrolmentStatus::Completed, 'All steps ran.');

            return;
        }

        $from ??= now();

        // A wait step's delay is added when it is queued, so its scheduled_at on the timeline
        // is the moment the step AFTER the wait becomes eligible.
        $scheduledAt = $next->kind === StepKind::Wait
            ? $from->modify('+'.(int) $next->wait_minutes.' minutes')
            : $from;

        AutomationStepRun::create([
            'enrolment_id' => $enrolment->id,
            'step_id' => $next->id,
            'status' => RunStatus::Pending,
            'scheduled_at' => $scheduledAt,
        ]);
    }
}
