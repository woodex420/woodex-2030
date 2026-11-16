<?php

declare(strict_types=1);

namespace App\Domain\Projects\Models;

use App\Domain\Sales\Models\QuoteMilestone;
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
