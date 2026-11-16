<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;

final class Series extends Model
{
    protected $table = 'commerce_series';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['content_version' => 'integer', 'draft_revision_id' => 'integer', 'published_revision_id' => 'integer', 'position' => 'integer', 'is_active' => 'boolean'];
    }
}
