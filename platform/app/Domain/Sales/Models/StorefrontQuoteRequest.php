<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use Illuminate\Database\Eloquent\Model;

/** Request snapshot only. Not an issued Quote, order, price promise or stock reservation. */
final class StorefrontQuoteRequest extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['lines' => 'array', 'estimated_total_minor' => 'integer'];
    }
}
