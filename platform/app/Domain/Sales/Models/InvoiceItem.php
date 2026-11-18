<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Commerce\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string|null $product_name
 * @property int|null $product_id
 * @property string|null $product_code
 */
class InvoiceItem extends Model
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
            'discount_minor' => 'integer',
            'tax_bp' => 'integer',
            'line_total_minor' => 'integer',
            'position' => 'integer',
            'product_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * The product of the catalogue the quotation's line was chosen from (T-086). Only a link.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
