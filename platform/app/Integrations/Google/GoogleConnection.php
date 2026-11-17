<?php

declare(strict_types=1);

namespace App\Integrations\Google;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Hidden(['access_token', 'refresh_token'])]
final class GoogleConnection extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'scopes' => 'array',
            'expires_at' => 'datetime', 'connected_at' => 'datetime', 'last_checked_at' => 'datetime'];
    }
}
