<?php

declare(strict_types=1);

namespace App\Integrations\Webhooks;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One place Woodex calls out to — Zapier, Make, the owner's CRM. The signing secret is encrypted at
 * rest and shown once, at creation; deliveries are the receipts.
 *
 * @property string $secret
 * @property list<string>|null $events
 */
final class OutboundWebhook extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['secret'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'outbound_webhook_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<OutboundWebhook>  $query
     * @return Builder<OutboundWebhook>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function subscribesTo(string $event): bool
    {
        return in_array($event, $this->events ?? [], true);
    }

    /** Enough of the signing secret to recognise it — never enough to use it. */
    public function secretSuffix(): string
    {
        $secret = (string) $this->secret;

        return $secret === '' ? '' : '••••'.mb_substr($secret, -4);
    }

    public static function generateSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }
}
