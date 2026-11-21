<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Models;

use App\Domain\Accounts\Enums\BillStatus;
use App\Domain\Projects\Models\Project;
use App\Models\User;
use App\Support\Concerns\HasPublicId;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * A received invoice — the vendor's bill to the studio (slice I). Posting it books the cost and the payable;
 * paying it clears the payable. The totals are stored in integer paisa and never recomputed from the UI.
 */
class Bill extends Model
{
    use HasPublicId;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'status' => BillStatus::class,
            'bill_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'voided_at' => 'datetime',
            'subtotal_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'paid_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return HasMany<BillItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(BillItem::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<BillPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(BillPayment::class)->orderBy('paid_on')->orderBy('id');
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** How many days ahead "due soon" looks: a bill that falls due within a week is paid this week. */
    public const DUE_SOON_DAYS = 7;

    /**
     * Bills the studio still owes on: received or part-paid (BillStatus::isOpen). A draft has not arrived yet
     * and a void one is taken back.
     *
     * @param  Builder<Bill>  $query
     * @return Builder<Bill>
     */
    public function scopeOwing(Builder $query): Builder
    {
        return $query->whereIn('bills.status', [BillStatus::Received->value, BillStatus::PartiallyPaid->value]);
    }

    /**
     * Bills that are owed on, by when they fall due: `overdue` is past the due date (isOverdue()), `soon` is due
     * today or within the next seven days, `open` is all of them. The dashboard's figures and the aging screen
     * they open ask this one scope (master list T-044).
     *
     * @param  Builder<Bill>  $query
     * @return Builder<Bill>
     */
    public function scopeDue(Builder $query, string $when): Builder
    {
        $today = CarbonImmutable::today();
        $query->owing();

        return match ($when) {
            'open' => $query,
            'overdue' => $query->whereDate('bills.due_date', '<', $today->toDateString()),
            'soon' => $query->whereDate('bills.due_date', '>=', $today->toDateString())
                ->whereDate('bills.due_date', '<=', $today->addDays(self::DUE_SOON_DAYS)->toDateString()),
            default => throw new InvalidArgumentException("A bill is not due [{$when}]."),
        };
    }

    public function balanceMinor(): int
    {
        return max(0, (int) $this->total_minor - (int) $this->paid_minor);
    }

    public function balance(): Money
    {
        return Money::ofMinor($this->balanceMinor());
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function canVoid(): bool
    {
        return $this->status !== BillStatus::Void && (int) $this->paid_minor === 0;
    }

    public function isOverdue(): bool
    {
        return $this->isOpen()
            && $this->due_date !== null
            && $this->due_date->isBefore(today());
    }

    /** Days past the due date, 0 when not overdue. */
    public function daysOverdue(): int
    {
        if (! $this->isOverdue() || $this->due_date === null) {
            return 0;
        }

        return (int) $this->due_date->diffInDays(today());
    }
}
