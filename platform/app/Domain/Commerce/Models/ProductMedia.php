<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;

final class ProductMedia extends Model
{
    protected $table = 'commerce_product_media';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['product_id' => 'integer'];
    }
}
