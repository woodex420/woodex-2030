<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Models;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Crm\Enums\BusinessLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One account in the chart of accounts (docs/18 §2.2). Groups organise the chart; only leaves
 * accept postings. No opening amounts are ever fabricated — balances come only from posted journals.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property AccountType $type
 * @property int|null $parent_id
 * @property BusinessLine|null $business_line
 * @property bool $is_group
 * @property bool $is_active
 */
class Account extends Model
{
    protected $guarded = ['id'];

    protected $table = 'chart_of_accounts';

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'business_line' => BusinessLine::class,
            'is_group' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    /** A posting target must be an active leaf — groups exist to organise, not to receive money. */
    public function acceptsPostings(): bool
    {
        return $this->is_active && ! $this->is_group;
    }

    public function label(): string
    {
        return $this->code.' · '.$this->name;
    }
}
