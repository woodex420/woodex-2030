<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Crm\Enums\BusinessLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Where clients pay. Interior → "WOODEX INTERIOR", Furniture → "WOODEX FURNITURE" (INV-004).
 * Real account numbers are entered by the owner in the admin; never seeded or committed.
 */
class BankAccount extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_line' => BusinessLine::class,
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Accounts that can receive payments for a business line (line-specific first, then shared).
     *
     * @param  Builder<BankAccount>  $query
     * @return Builder<BankAccount>
     */
    public function scopeForLine(Builder $query, BusinessLine $line): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->where('business_line', $line->value)->orWhereNull('business_line'))
            ->orderByRaw('business_line is null')
            ->orderByDesc('is_default')
            ->orderBy('position');
    }
}
