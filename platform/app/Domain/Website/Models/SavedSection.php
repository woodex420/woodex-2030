<?php

declare(strict_types=1);

namespace App\Domain\Website\Models;

use App\Domain\Website\Enums\BlockType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A section configured once and kept for reuse (V2-2, docs/12 §5.2): the studio saves a gallery or a price
 * table that took an hour to get right, then drops the same thing onto any page from the library.
 */
class SavedSection extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'type' => BlockType::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
