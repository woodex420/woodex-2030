<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment method's money lands in this ledger account when a receipt is posted (P8.2).
 * Seeded with defaults, owned by the owner on Accounts → Payment methods.
 *
 * @property int $id
 * @property string $method
 * @property int $account_id
 */
class PaymentMethodAccount extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'method' => 'string',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
