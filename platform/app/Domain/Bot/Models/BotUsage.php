<?php

declare(strict_types=1);

namespace App\Domain\Bot\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One day of the assistant on one channel with one provider (BOT-008/009): requests, replies,
 * tokens, hand-offs, guardrail hits. The daily cap reads the same row it writes — there is no
 * second book to keep in step.
 */
class BotUsage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'usage_date' => 'date',
            'requests' => 'integer',
            'replies' => 'integer',
            'tokens_in' => 'integer',
            'tokens_out' => 'integer',
            'handoffs' => 'integer',
            'guardrail_hits' => 'integer',
        ];
    }

    public static function today(string $channel, string $provider): self
    {
        return self::query()->firstOrCreate(
            ['usage_date' => now()->toDateString(), 'channel' => $channel, 'provider' => $provider],
            ['requests' => 0, 'replies' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'handoffs' => 0, 'guardrail_hits' => 0],
        );
    }
}
