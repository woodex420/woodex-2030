<?php

declare(strict_types=1);

namespace App\Domain\System\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person's saved filters/sort/columns for one list screen (GLB-003).
 *
 * @property array<string, mixed> $filters
 */
class SavedView extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['filters' => 'array', 'is_default' => 'boolean'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
