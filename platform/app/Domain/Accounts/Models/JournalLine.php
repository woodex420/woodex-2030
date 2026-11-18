<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a journal entry: exactly one side (debit or credit) carries a positive amount.
 *
 * @property int $id
 * @property int $journal_entry_id
 * @property int $account_id
 * @property int $debit_minor
 * @property int $credit_minor
 * @property string|null $memo
 */
class JournalLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'debit_minor' => 'integer',
            'credit_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
