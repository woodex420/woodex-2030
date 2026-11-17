<?php

declare(strict_types=1);

namespace App\Domain\Automation\Models;

use App\Domain\Automation\Enums\RunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The execution log (AUT-005): one row per step of an enrolment, with what it did, when, and
 * what stopped it. `attempt` climbs when a run is rescheduled (quiet hours, the daily cap) so a
 * held message is visible as held, not as silence.
 */
class AutomationStepRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'scheduled_at' => 'datetime',
            'ran_at' => 'datetime',
            'attempt' => 'integer',
        ];
    }

    /** @return BelongsTo<AutomationEnrolment, $this> */
    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(AutomationEnrolment::class, 'enrolment_id');
    }

    /** @return BelongsTo<AutomationStep, $this> */
    public function step(): BelongsTo
    {
        return $this->belongsTo(AutomationStep::class, 'step_id');
    }

    public function done(?string $note = null): void
    {
        $this->forceFill(['status' => RunStatus::Done, 'ran_at' => now(), 'note' => $note ?? $this->note])->save();
    }

    public function skipped(string $note): void
    {
        $this->forceFill(['status' => RunStatus::Skipped, 'ran_at' => now(), 'note' => $note])->save();
    }

    public function failed(string $error): void
    {
        $this->forceFill(['status' => RunStatus::Failed, 'ran_at' => now(), 'error' => $error])->save();
    }

    /** Quiet hours, the daily cap: the message waits, visibly, for its next legal moment. */
    public function holdUntil(\DateTimeInterface $when, string $why): void
    {
        $this->forceFill([
            'status' => RunStatus::Pending,
            'scheduled_at' => $when,
            'attempt' => $this->attempt + 1,
            'note' => $why,
        ])->save();
    }
}
