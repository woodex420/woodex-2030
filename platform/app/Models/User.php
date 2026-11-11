<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Crm\Models\Lead;
use App\Domain\System\Models\TelegramLink;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * A Woodex team member (staff). Clients never log in with a password (portal uses magic links, P8).
 */
#[Fillable(['name', 'email', 'password', 'phone', 'title', 'is_active', 'locale', 'last_login_at', 'last_login_ip'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Lead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'owner_id');
    }

    /**
     * @return HasOne<TelegramLink, $this>
     */
    public function telegramLink(): HasOne
    {
        return $this->hasOne(TelegramLink::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeTelegramOwners(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereHas('roles', fn ($roles) => $roles->where('name', 'owner')->where('guard_name', 'web'));
    }

    /** Fresh authority: cached roles must not survive demotion, deactivation or deletion. */
    public function canUseTelegram(): bool
    {
        return $this->exists && static::query()->telegramOwners()->whereKey($this->getKey())->exists();
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Owner enrolment is optional; confirmed 2FA is still challenged by Fortify at sign-in.
     * The explicit exemption also applies to multi-role owners and existing cached role lists.
     */
    public function requiresTwoFactorAuthentication(): bool
    {
        return ! $this->hasRole('owner')
            && (bool) config('woodex.security.enforce_2fa')
            && $this->hasAnyRole((array) config('woodex.security.require_2fa_roles', []));
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => Str::upper(Str::substr($part, 0, 1)))
            ->implode('');
    }

    /**
     * The chat this staff member's usable link points at, ignoring any mute (S8).
     *
     * Delivery decisions belong to the Telegram channel: a muted chat's alerts are
     * queued (PendingTelegramMessage) rather than dropped, so this method exists to
     * answer "could this notification ever reach Telegram?" without the mute hiding it.
     */
    public function telegramChatIdIgnoringMute(): ?int
    {
        return $this->telegramLink()->usable()->first()?->chat_id;
    }

    /**
     * Telegram chat for alerts, if this user linked one (TGM-001). Muted chats get
     * nothing straight away — the channel queues their messages until the mute ends.
     */
    public function routeNotificationForTelegram(): ?int
    {
        $link = $this->telegramLink()->usable()->first();

        if ($link === null) {
            return null;
        }

        if ($link->muted_until !== null && $link->muted_until->isFuture()) {
            return null;
        }

        return (int) $link->chat_id;
    }
}
