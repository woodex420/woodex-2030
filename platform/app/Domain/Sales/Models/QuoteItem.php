<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Commerce\Models\Product;
use App\Domain\Sales\Enums\DiscountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BOQ line. qty is DECIMAL(14,3) (kept as string to avoid float), money in paisa.
 */
/**
 * @property string|null $product_name
 * @property int|null $product_id
 * @property string|null $product_code
 * @property bool $price_on_request
 */
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
            'product_id' => 'integer',
            'price_on_request' => 'boolean',
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

    /**
     * The product of the catalogue this line was chosen from (T-086). Only a link: the name, the code and the
     * price on the line are the line's own and are never read from here.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
