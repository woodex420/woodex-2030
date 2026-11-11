<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An accounting period (docs/18 §2.3): posting needs an open period that covers the business date.
 * Closing is explicit and reversible, with the actor recorded.
 *
 * @property int $id
 * @property string $name
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property CarbonImmutable|null $closed_at
 * @property int|null $closed_by
 */
class AccountingPeriod extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    public function covers(string $businessDate): bool
    {
        $day = CarbonImmutable::parse($businessDate)->startOfDay();

        return $day->between($this->starts_on, $this->ends_on);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCovering(Builder $query, string $businessDate): Builder
    {
        return $query->whereDate('starts_on', '<=', $businessDate)->whereDate('ends_on', '>=', $businessDate);
    }
}
