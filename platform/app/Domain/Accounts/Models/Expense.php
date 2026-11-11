<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Models;

use App\Domain\Accounts\Enums\ExpenseDirection;
use App\Domain\Accounts\Enums\PaidFrom;
use App\Domain\Projects\Models\Project;
use App\Models\User;
use App\Support\Concerns\HasPublicId;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-off expense or a piece of other income, entered straight into the books (slice I). The journal is
 * created with the row: Dr the account and Cr the money account when money left; the reverse when it came in.
 */
class Expense extends Model
{
    use HasPublicId;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'direction' => ExpenseDirection::class,
            'paid_from' => PaidFrom::class,
            'spent_on' => 'immutable_date',
            'project_id' => 'integer',
            'amount_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payment_account_id');
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

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return BelongsTo<RecurringExpense, $this> */
    public function recurring(): BelongsTo
    {
        return $this->belongsTo(RecurringExpense::class, 'recurring_expense_id');
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
