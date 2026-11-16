<?php

declare(strict_types=1);

namespace App\Integrations\Webhooks;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt series for one event at one endpoint: the payload as sent, how many tries it took,
 * and what the endpoint answered. The hub reads these rows for its delivery history.
 *
 * @property array<string, mixed> $payload
 */
final class WebhookDelivery extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'response_status' => 'integer',
            'delivered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<OutboundWebhook, $this> */
    public function webhook(): BelongsTo
    {
        return $this->belongsTo(OutboundWebhook::class, 'outbound_webhook_id');
    }
}
