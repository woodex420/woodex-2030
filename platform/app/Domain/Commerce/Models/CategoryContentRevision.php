<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only content snapshot; drafts/published pointers belong to Category. */
final class CategoryContentRevision extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['content' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
