<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Domain\Crm\Enums\VisitStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Site visit with reminders, measurements and outcome (CRM-008).
 */
class SiteVisit extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VisitStatus::class,
            'scheduled_at' => 'datetime',
            'client_confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'reminders' => 'array',
            'measurements' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function endsAt(): CarbonInterface
    {
        return $this->scheduled_at->copy()->addMinutes((int) $this->duration_minutes);
    }

    /**
     * Visits still to happen (scheduled, confirmed or rescheduled, from now on).
     *
     * @param  Builder<SiteVisit>  $query
     */
    public function scopeUpcoming(Builder $query): void
    {
        $query->whereIn('status', [VisitStatus::Scheduled, VisitStatus::Confirmed, VisitStatus::Rescheduled])
            ->where('scheduled_at', '>=', now());
    }

    /**
     * Open visits of one team member that overlap the given slot (conflict warning, CRM-008).
     * Durations are short, so candidates are narrowed to the same day and compared in PHP (portable SQL).
     *
     * @return Collection<int, SiteVisit>
     */
    public static function overlapping(int $userId, CarbonImmutable $start, int $minutes, ?int $ignoreId = null): Collection
    {
        $end = $start->addMinutes($minutes);

        return self::query()
            ->with('contact:id,name')
            ->where('assigned_to', $userId)
            ->whereIn('status', [VisitStatus::Scheduled, VisitStatus::Confirmed, VisitStatus::Rescheduled])
            ->whereBetween('scheduled_at', [$start->subHours(8), $end])
            ->when($ignoreId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->orderBy('scheduled_at')
            ->get()
            ->filter(fn (SiteVisit $visit): bool => $visit->scheduled_at->lt($end) && $visit->endsAt()->gt($start))
            ->values();
    }
}
