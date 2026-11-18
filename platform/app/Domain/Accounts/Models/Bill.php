<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Models;

use App\Domain\Accounts\Enums\BillStatus;
use App\Domain\Projects\Models\Project;
use App\Models\User;
use App\Support\Concerns\HasPublicId;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
