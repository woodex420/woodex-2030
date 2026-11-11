<?php

declare(strict_types=1);

namespace App\Integrations\Connectors;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A provider the company has authorised. The credential payload is encrypted at rest and hidden from every
 * array/JSON representation; only the connector driver reads it.
 *
 * @property array<string, mixed> $credentials
 * @property array<int, string>|null $scopes
 * @property array<string, mixed>|null $meta
 */
final class IntegrationConnection extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['credentials'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'scopes' => 'array',
            'meta' => 'array',
            'connected_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function connecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    public function isHealthy(): bool
    {
        return $this->status === 'connected';
    }
}
