<?php

declare(strict_types=1);

namespace App\Domain\System\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Masked log of outbound/inbound integration calls (GLB-018). Pruned after 30 days.
 */
class IntegrationLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ok' => 'boolean',
            'created_at' => 'datetime',
            'request' => 'array',
            'response' => 'array',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
