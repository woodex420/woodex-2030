<?php

declare(strict_types=1);

namespace App\Domain\System\Models;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owner-only private Telegram link. Legacy staff/group rows are retained but carry no authority.
 */
class TelegramLink extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
            'is_active' => 'boolean',
            'muted_until' => 'datetime',
            'link_code_expires_at' => 'datetime',
            'linked_at' => 'datetime',
            'preferences' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Messages waiting out this chat's quiet hours (S8). Delivered by woodex:telegram-flush.
     *
     * @return HasMany<PendingTelegramMessage, $this>
     */
    public function pendingMessages(): HasMany
    {
        return $this->hasMany(PendingTelegramMessage::class, 'telegram_link_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('chat_id', '>', 0)
            ->where(fn ($chats) => $chats->where('chat_type', 'private')->orWhereNull('chat_type'))
            ->whereIn('user_id', User::query()->telegramOwners()->select('id'));
    }

    /**
     * Create (or refresh) a one-time code the user sends to the bot as "/start CODE".
     */
    public static function issueCodeFor(User $user, int $minutes = 30): self
    {
        return DB::transaction(function () use ($user, $minutes): self {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            if (! $user->canUseTelegram()) {
                throw new AuthorizationException;
            }
            $link = self::query()->where('user_id', $user->id)->lockForUpdate()->first()
                ?? new self(['user_id' => $user->id]);
            $link->link_code = Str::upper(Str::random(16));
            $link->link_code_expires_at = now()->addMinutes(min(30, max(1, $minutes)));
            $link->save();

            return $link;
        });
    }
}
