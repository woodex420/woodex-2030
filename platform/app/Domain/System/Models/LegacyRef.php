<?php

declare(strict_types=1);

namespace App\Domain\System\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Maps a v2.1 row (legacy_table + legacy_id) to the imported model, so the importer is idempotent.
 */
class LegacyRef extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'imported_at' => 'datetime',
        ];
    }
}
