<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Models;

use App\Domain\Crm\Enums\BusinessLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A journal entry (docs/18 §2.4/§2.5): total debits always equal total credits, in integer paisa,
 * with one side per line. Posted entries are immutable — corrections are linked reversals.
 *
 * @property int $id
 * @property string $entry_no
 * @property string|null $source_type
 * @property int|null $source_id
 * @property CarbonImmutable $business_date
 * @property string|null $reference
 * @property int $created_by
 * @property BusinessLine|null $business_line
 * @property int|null $project_id
 * @property string|null $note
 * @property int|null $reverses_entry_id
 * @property int|null $reversed_by_entry_id
 */
class JournalEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date',
            'business_line' => BusinessLine::class,
        ];
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<self, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    /** @return BelongsTo<self, $this> */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_entry_id');
    }

    public function isReversal(): bool
    {
        return $this->reverses_entry_id !== null;
    }

    public function isReversed(): bool
    {
        return $this->reversed_by_entry_id !== null;
    }

    /** @return array{debit_minor: int, credit_minor: int} */
    public function totals(): array
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        return [
            'debit_minor' => (int) $lines->sum('debit_minor'),
            'credit_minor' => (int) $lines->sum('credit_minor'),
        ];
    }
}
