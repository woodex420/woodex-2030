<?php

declare(strict_types=1);

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Enums\CallOutcome;
use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\PipelineStage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Log a call, WhatsApp, email, meeting or note on a lead (CRM-016, GLB-004).
 *
 * - Any outbound attempt counts as the first response for the SLA (CRM-010).
 * - Actually reaching the client moves a New lead to Contacted.
 */
final class LogLeadActivity
{
    /** @var list<ActivityType> */
    private const OUTBOUND = [ActivityType::Call, ActivityType::WhatsApp, ActivityType::Email, ActivityType::Meeting];

    public function __construct(
        private readonly MoveLeadToStage $moveLeadToStage,
        private readonly NotifyMentions $notifyMentions,
    ) {}

    public function handle(Lead $lead, ActivityType $type, ?User $by = null, ?string $body = null, ?CallOutcome $outcome = null): Activity
    {
        $activity = DB::transaction(function () use ($lead, $type, $by, $body, $outcome): Activity {
            $now = now();
            $outbound = in_array($type, self::OUTBOUND, true);

            $activity = Activity::create([
                'contact_id' => $lead->contact_id,
                'lead_id' => $lead->id,
                'user_id' => $by?->id,
                'type' => $type,
                'direction' => $outbound ? 'out' : null,
                'outcome' => $outcome?->value,
                'body' => $body !== null && trim($body) !== '' ? trim($body) : null,
                'occurred_at' => $now,
            ]);

            $lead->last_activity_at = $now;

            if ($outbound && $lead->first_response_at === null) {
                $lead->first_response_at = $now;
            }

            $lead->save();
            $lead->contact()->update(['last_activity_at' => $now]);

            if ($this->reached($type, $outcome) && $lead->stage()->value('key') === 'new') {
                $this->moveLeadToStage->handle($lead->refresh(), PipelineStage::byKey('contacted'), $by);
            }

            return $activity;
        });

        // Notify after commit so a failed write never sends a mention.
        if ($activity->body !== null) {
            $this->notifyMentions->handle($activity, $by);
        }

        return $activity;
    }

    public function reached(ActivityType $type, ?CallOutcome $outcome): bool
    {
        return match ($type) {
            ActivityType::Call => $outcome?->reached() ?? false,
            ActivityType::WhatsApp, ActivityType::Email, ActivityType::Meeting => true,
            default => false,
        };
    }

    /**
     * Number of most recent consecutive unanswered calls (three in a row suggests WhatsApp instead).
     */
    public function consecutiveNoAnswers(Lead $lead): int
    {
        $outcomes = Activity::query()
            ->where('lead_id', $lead->id)
            ->where('type', ActivityType::Call->value)
            ->latest('occurred_at')
            ->latest('id')
            ->limit(5)
            ->pluck('outcome');

        $count = 0;

        foreach ($outcomes as $value) {
            if (! in_array($value, [CallOutcome::NoAnswer->value, CallOutcome::Busy->value, CallOutcome::SwitchedOff->value], true)) {
                break;
            }

            $count++;
        }

        return $count;
    }
}
