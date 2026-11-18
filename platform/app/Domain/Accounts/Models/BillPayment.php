<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Models;

use App\Domain\Sales\Enums\PaymentMethod;
use App\Models\User;
use App\Support\Concerns\HasPublicId;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money paid out against a received invoice (slice I). Its journal is Dr Accounts payable / Cr cash or bank. */
class BillPayment extends Model
{
    use HasPublicId;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'paid_on' => 'immutable_date',
            'amount_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<Bill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
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

    public function amount(): Money
    {
        return Money::ofMinor((int) $this->amount_minor);
    }
}
