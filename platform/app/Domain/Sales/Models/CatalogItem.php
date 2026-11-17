<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Crm\Enums\BusinessLine;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * Rate book entry used by the quotation builder (QUO-001).
 */
class CatalogItem extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate_minor' => 'integer',
            'internal_cost_minor' => 'integer',
            'business_line' => BusinessLine::class,
            'is_active' => 'boolean',
        ];
    }

    public function rate(): Money
    {
        return Money::ofMinor((int) $this->rate_minor);
    }
}
