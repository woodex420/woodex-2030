<?php

declare(strict_types=1);

namespace App\Domain\Projects\Models;

use App\Domain\Projects\Enums\ProjectExpenseCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One purchase or expense line on a job (PRJ-009): what the work actually cost,
 * against what the quote's internal cost said it would.
 *
 * @property ProjectExpenseCategory $category
 */
class ProjectExpense extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => ProjectExpenseCategory::class,
            'qty' => 'decimal:3',
            'amount_minor' => 'integer',
            'incurred_on' => 'date',
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
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
