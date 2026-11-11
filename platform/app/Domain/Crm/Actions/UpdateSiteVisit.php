<?php

declare(strict_types=1);

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Enums\NextActionType;
use App\Domain\Crm\Enums\TaskStatus;
use App\Domain\Crm\Enums\TaskType;
use App\Domain\Crm\Enums\VisitStatus;
use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\SiteVisit;
use App\Domain\Crm\Models\Task;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Site visit status changes: confirm, reschedule, no-show, cancel, complete with outcome (CRM-008).
 */
final class UpdateSiteVisit
{
    public function confirm(SiteVisit $visit, ?User $by = null): SiteVisit
    {
        $visit->forceFill(['status' => VisitStatus::Confirmed, 'client_confirmed_at' => now()])->save();
        $this->log($visit, 'Client confirmed the site visit', $by);

        return $visit;
    }

    public function reschedule(SiteVisit $visit, CarbonInterface $at, ?User $by = null): SiteVisit
    {
        return DB::transaction(function () use ($visit, $at, $by): SiteVisit {
            $visit->forceFill(['status' => VisitStatus::Rescheduled, 'scheduled_at' => $at, 'reminders' => null])->save();
            $visit->lead->forceFill(['next_action_type' => NextActionType::Visit, 'next_action_at' => $at])->save();
            $this->log($visit, 'Site visit moved to '.$at->timezone('Asia/Karachi')->format('D j M, g:i a'), $by);

            return $visit;
        });
    }

    public function markNoShow(SiteVisit $visit, ?User $by = null): SiteVisit
    {
        return DB::transaction(function () use ($visit, $by): SiteVisit {
            $visit->forceFill(['status' => VisitStatus::NoShow])->save();
            $visit->lead->forceFill(['next_action_type' => NextActionType::Call, 'next_action_at' => now()->addHours(2)])->save();
            $this->log($visit, 'Client was not available for the site visit (no-show)', $by);

            return $visit;
        });
    }

    public function cancel(SiteVisit $visit, ?string $reason = null, ?User $by = null): SiteVisit
    {
        $visit->forceFill(['status' => VisitStatus::Cancelled, 'outcome_notes' => $reason])->save();
        $this->log($visit, 'Site visit cancelled'.($reason ? ": {$reason}" : ''), $by);

        return $visit;
    }

    /**
     * @param  list<array{room?: string|null, length_ft?: string|int|float|null, width_ft?: string|int|float|null}>  $measurements
     */
    public function complete(SiteVisit $visit, string $notes, array $measurements = [], ?string $nextStep = null, ?User $by = null): SiteVisit
    {
        if (trim($notes) === '') {
            throw new InvalidArgumentException('Visit notes are required.');
        }

        return DB::transaction(function () use ($visit, $notes, $measurements, $nextStep, $by): SiteVisit {
            $rows = self::normalizeMeasurements($measurements);
            $totalArea = array_sum(array_column($rows, 'area_sqft'));

            $visit->forceFill([
                'status' => VisitStatus::Completed,
                'completed_at' => now(),
                'outcome_notes' => trim($notes),
                'measurements' => $rows === [] ? null : $rows,
                'next_step' => $nextStep,
            ])->save();

            $lead = $visit->lead;
            $owner = $lead->owner ?? $by;

            if ($lead->area_sqft === null && $totalArea > 0) {
                $lead->area_sqft = (int) round($totalArea);
            }

            // Quote turnaround target: visit done → quotation sent within 48 hours (KPI, PRD §3).
            $lead->next_action_type = NextActionType::Call;
            $lead->next_action_at = now()->addHours(48);
            $lead->last_activity_at = now();
            $lead->save();

            Task::create([
                'title' => "Prepare quotation for {$lead->contact->name} ({$lead->ref})",
                'type' => TaskType::Todo,
                'due_at' => now()->addHours(48),
                'assignee_id' => $owner?->id,
                'created_by' => $by?->id,
                'lead_id' => $lead->id,
                'contact_id' => $lead->contact_id,
                'priority' => 'high',
                'status' => TaskStatus::Open,
            ]);

            $this->log($visit, 'Site visit completed: '.trim($notes)
                .($totalArea > 0 ? sprintf(' · Measured %s sq ft', number_format($totalArea)) : '')
                .($nextStep ? " · Next: {$nextStep}" : ''), $by);

            return $visit;
        });
    }

    /**
     * @param  list<array{room?: string|null, length_ft?: string|int|float|null, width_ft?: string|int|float|null}>  $measurements
     * @return list<array{room: string, length_ft: float, width_ft: float, area_sqft: float}>
     */
    public static function normalizeMeasurements(array $measurements): array
    {
        $rows = [];

        foreach ($measurements as $row) {
            $length = (float) ($row['length_ft'] ?? 0);
            $width = (float) ($row['width_ft'] ?? 0);
            $room = trim((string) ($row['room'] ?? ''));

            if ($length <= 0 || $width <= 0) {
                continue;
            }

            $rows[] = [
                'room' => $room !== '' ? $room : 'Area '.(count($rows) + 1),
                'length_ft' => round($length, 2),
                'width_ft' => round($width, 2),
                'area_sqft' => round($length * $width, 2),
            ];
        }

        return $rows;
    }

    private function log(SiteVisit $visit, string $body, ?User $by): void
    {
        Activity::create([
            'contact_id' => $visit->contact_id,
            'lead_id' => $visit->lead_id,
            'user_id' => $by?->id,
            'subject_type' => 'site_visit',
            'subject_id' => $visit->id,
            'type' => ActivityType::Visit,
            'body' => $body,
            'data' => ['visit_id' => $visit->id, 'status' => $visit->status->value],
            'occurred_at' => now(),
        ]);
    }
}
