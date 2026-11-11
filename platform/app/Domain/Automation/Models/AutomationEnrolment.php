<?php

declare(strict_types=1);

namespace App\Domain\Automation\Models;

use App\Domain\Automation\Enums\EnrolmentStatus;
use App\Domain\Crm\Models\Lead;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A lead inside a sequence (AUT-001). Pause/resume/cancel (AUT-005) only touch this row and the
 * runs below it — nothing is deleted, so the history of "what did we send and why" survives.
 */
class AutomationEnrolment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => EnrolmentStatus::class,
            'enrolled_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AutomationSequence, $this> */
    public function sequence(): BelongsTo
    {
        return $this->belongsTo(AutomationSequence::class, 'sequence_id');
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return HasMany<AutomationStepRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(AutomationStepRun::class, 'enrolment_id')->orderBy('scheduled_at');
    }

    public function pause(): void
    {
        $this->forceFill(['status' => EnrolmentStatus::Paused])->save();
    }

    public function resume(): void
    {
        if ($this->status !== EnrolmentStatus::Paused) {
            return;
        }

        // Runs that came due while paused are pushed from their missed slot to now, never dropped.
        $this->runs()->where('status', 'pending')->where('scheduled_at', '<=', now())->update(['scheduled_at' => now()]);

        $this->forceFill(['status' => EnrolmentStatus::Active])->save();
    }

    public function end(EnrolmentStatus $status, string $reason): void
    {
        if (in_array($this->status, [EnrolmentStatus::Completed, EnrolmentStatus::Cancelled, EnrolmentStatus::Exited], true)) {
            return;
        }

        $this->forceFill(['status' => $status, 'ended_at' => now(), 'end_reason' => $reason])->save();
    }
}
