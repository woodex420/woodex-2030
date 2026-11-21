<?php

declare(strict_types=1);

namespace App\Domain\Projects\Models;

use App\Domain\Sales\Models\QuoteMilestone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMilestone extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'percent_bp' => 'integer',
            'amount_minor' => 'integer',
            'due_date' => 'date',
            'reached_at' => 'datetime',
            'position' => 'integer',
        ];
    }

    /**
     * Milestones that are not reached and fall due today or within the next fourteen days
     * (Project::MILESTONE_SOON_DAYS). Written once, for the dashboard's list and for Project::milestoneSoon().
     *
     * @param  Builder<ProjectMilestone>  $query
     */
    public static function comingSoon(Builder $query): void
    {
        $today = CarbonImmutable::today();

        $query->whereNull('reached_at')
            ->whereDate('due_date', '>=', $today->toDateString())
            ->whereDate('due_date', '<=', $today->addDays(Project::MILESTONE_SOON_DAYS)->toDateString());
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<QuoteMilestone, $this>
     */
    public function quoteMilestone(): BelongsTo
    {
        return $this->belongsTo(QuoteMilestone::class);
    }
}
