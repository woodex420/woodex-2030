<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Models;

use App\Domain\Accounts\Enums\PaidFrom;
use App\Domain\Sales\Enums\RecurringFrequency;
use App\Models\User;
use App\Support\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A repeating overhead (slice I): rent, salaries, a subscription. `next_run_on` is the only schedule — the
 * command reads it, raises one expense, posts it and advances the date from itself, never from today.
 */
class RecurringExpense extends Model
{
    use HasPublicId;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'frequency' => RecurringFrequency::class,
            'paid_from' => PaidFrom::class,
            'starts_on' => 'immutable_date',
            'next_run_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'last_raised_at' => 'datetime',
            'amount_minor' => 'integer',
            'raised_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereDate('next_run_on', '<=', today());
    }

    public function isFinished(): bool
    {
        return $this->ends_on !== null && $this->next_run_on->greaterThan($this->ends_on);
    }

    /** The next date in the series, computed from the date just raised. */
    public function advanceFrom(CarbonImmutable $date): CarbonImmutable
    {
        return $this->frequency->advance($date);
    }
}
