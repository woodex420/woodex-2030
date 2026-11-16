<?php

declare(strict_types=1);

namespace App\Domain\Website\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A snapshot of a page before a publish (docs/09 §2.1): one click restores title, SEO fields and every block.
 *
 * @property int $id
 * @property int $page_id
 * @property array<string, mixed> $data
 * @property ?int $user_id
 */
class PageVersion extends Model
{
    public $timestamps = false;

    protected $fillable = ['page_id', 'data', 'user_id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
