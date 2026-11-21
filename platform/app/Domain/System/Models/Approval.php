<?php

declare(strict_types=1);

namespace App\Domain\System\Models;

use App\Domain\Sales\Models\Quote;
use App\Domain\System\Enums\ApprovalStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Approval request for discounts, low margins, voids and refunds (QUO-007).
 */
class Approval extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ApprovalStatus::class,
            'decided_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    /**
     * Quotations held for a decision (QUO-007): the request is still pending and its quotation still exists.
     * A request whose quotation was deleted waits for nobody, and the Approvals screen cannot show it, so the
     * dashboard does not count it either (master list T-044: a figure and the list it opens are one query).
     *
     * @param  Builder<Approval>  $query
     * @return Builder<Approval>
     */
    public function scopeQuotesWaiting(Builder $query): Builder
    {
        return $query->where('approvable_type', 'quote')
            ->where('status', ApprovalStatus::Pending->value)
            ->whereHasMorph('approvable', [Quote::class]);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
