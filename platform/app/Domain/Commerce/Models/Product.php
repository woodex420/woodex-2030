<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;

final class Product extends Model
{
    protected $table = 'commerce_products';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['draft' => 'array', 'source_record' => 'array', 'review_issues' => 'array', 'version' => 'integer', 'published_revision_id' => 'integer', 'archived_at' => 'datetime'];
    }
}
