<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Crm\Enums\BusinessLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class DescriptionTemplate extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['business_line' => BusinessLine::class, 'is_active' => 'boolean'];
    }

    /**
     * The descriptions of the brands this installation sells. The table is created with starter text for both
     * the Interior studio and Furniture; a library, unlike a client's record, shows only what is sold here.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOnSale(Builder $query): void
    {
        $query->whereIn('business_line', BusinessLine::offeredBrandValues());
    }
}
