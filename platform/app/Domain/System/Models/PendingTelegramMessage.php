<?php

declare(strict_types=1);

namespace App\Domain\System\Models;

use App\Integrations\Telegram\TelegramMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Telegram message waiting out a chat's quiet hours (S8 / TGM-008).
 *
 * The channel never drops a muted alert: it queues it here, and woodex:telegram-flush
 * delivers the backlog the moment the mute ends — oldest first. A hard cap per chat
 * keeps a very long weekend from turning into a flood.
 */
class PendingTelegramMessage extends Model
{
    /** Oldest-first, at most this many per chat; beyond that the oldest are let go. */
    public const CAP_PER_LINK = 20;

    protected $guarded = ['id'];

    /** queued_at is the record's clock; there is no row churn after delivery. */
    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'buttons' => 'array',
            'queued_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TelegramLink, $this>
     */
    public function link(): BelongsTo
    {
        return $this->belongsTo(TelegramLink::class, 'telegram_link_id');
    }

    /**
     * Queue a message for a muted chat, enforcing the cap. The message keeps its
     * inline buttons so the flushed copy works exactly like a live one.
     */
    public static function queue(TelegramLink $link, TelegramMessage $message): ?self
    {
        if (! TelegramLink::query()->usable()->whereKey($link->id)->exists()) {
            return null;
        }
        $undelivered = static::query()
            ->where('telegram_link_id', $link->id)
            ->whereNull('delivered_at')
            ->orderByDesc('id')
            ->skip(self::CAP_PER_LINK - 1)
            ->take(50)
            ->pluck('id');

        if ($undelivered->isNotEmpty()) {
            static::query()->whereIn('id', $undelivered)->delete();
        }

        return static::query()->create([
            'telegram_link_id' => $link->id,
            'text' => $message->text,
            'buttons' => $message->buttons === [] ? null : $message->buttons,
        ]);
    }

    public function toTelegramMessage(): TelegramMessage
    {
        return new TelegramMessage($this->text, $this->buttons ?? []);
    }
}
