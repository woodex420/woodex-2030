<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Sales\Enums\DiscountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BOQ line. qty is DECIMAL(14,3) (kept as string to avoid float), money in paisa.
 */
/** @property string|null $product_name */
class QuoteItem extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_name' => 'string',
            'qty' => 'decimal:3',
            'rate_minor' => 'integer',
            'discount_type' => DiscountType::class,
            'discount_value' => 'integer',
            'discount_minor' => 'integer',
            'line_total_minor' => 'integer',
            'internal_cost_minor' => 'integer',
            'is_optional' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * @return BelongsTo<QuoteSection, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(QuoteSection::class, 'quote_section_id');
    }

    /**
     * @return BelongsTo<CatalogItem, $this>
     */
    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }
}
