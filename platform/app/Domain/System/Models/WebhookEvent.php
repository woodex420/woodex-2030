<?php

declare(strict_types=1);

namespace App\Domain\System\Models;

use App\Domain\System\Enums\WebhookStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Every inbound webhook is stored once (unique provider + external_id) for idempotency and replay.
 */
class WebhookEvent extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_valid' => 'boolean',
            'status' => WebhookStatus::class,
            'processed_at' => 'datetime',
            'attempts' => 'integer',
            'queued_at' => 'datetime',
            'next_attempt_at' => 'datetime',
        ];
    }
}
