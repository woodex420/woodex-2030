<?php

declare(strict_types=1);

namespace App\Integrations\Google;

use App\Domain\System\Models\IntegrationLog;
use App\Integrations\Google\Contracts\GoogleGateway;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class GoogleOAuth
{
    public function __construct(private readonly GoogleGateway $google) {}

    public static function configured(): bool
    {
        $uri = (string) config('services.google.redirect_uri');
        $parts = parse_url($uri);
        $secure = is_array($parts) && ($parts['scheme'] ?? '') === 'https';
        $local = ! app()->isProduction() && is_array($parts) && ($parts['scheme'] ?? '') === 'http'
            && in_array($parts['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true);

        return filter_var($uri, FILTER_VALIDATE_URL) !== false && filled(config('services.google.client_id')) && filled(config('services.google.client_secret'))
            && ($secure || $local) && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['query']) && ! isset($parts['fragment']) && filled($parts['host'] ?? '');
    }

    public static function loginEnabled(): bool
    {
        return self::configured() && (bool) config('services.google.sign_in_enabled');
    }

    public function authorizeOwner(User $user): void
    {
        abort_unless($user->is_active && $user->hasRole('owner') && $user->can('integrations.manage'), 403);
    }

    /** @param array<string, mixed> $tokens
     * @return array{sub: string, email: string}
     */
    public function verifiedProfile(array $tokens): array
    {
        if (! is_string($tokens['access_token'] ?? null) || $tokens['access_token'] === ''
            || strtolower((string) ($tokens['token_type'] ?? '')) !== 'bearer') {
            throw new RuntimeException('Google token response invalid.');
        }
        $profile = $this->google->profile($tokens['access_token']);
        if (! is_string($profile['sub'] ?? null) || $profile['sub'] === '' || strlen($profile['sub']) > 255
            || ($profile['email_verified'] ?? null) !== true || ! is_string($profile['email'] ?? null)
            || ! filter_var($profile['email'], FILTER_VALIDATE_EMAIL) || strlen($profile['email']) > 255) {
            throw new RuntimeException('A verified Google identity is required.');
        }

        return ['sub' => $profile['sub'], 'email' => $profile['email']];
    }

    /** @param array{sub: string, email: string} $profile
     * @param  array<string, mixed>  $tokens
     */
    public function connect(User $user, array $profile, array $tokens): void
    {
        $this->authorizeOwner($user);
        // Deliberately identity-only. Do not silently accept additional Workspace permissions.
        $scopes = preg_split('/\s+/', trim((string) ($tokens['scope'] ?? ''))) ?: [];
        $allowed = ['openid', 'email', 'profile', 'https://www.googleapis.com/auth/userinfo.email', 'https://www.googleapis.com/auth/userinfo.profile'];
        if ($scopes === [] || array_diff($scopes, $allowed) !== []) {
            throw new RuntimeException('Unexpected Google permissions.');
        }
        $refresh = $tokens['refresh_token'] ?? null;
        if (! is_string($refresh) || $refresh === '') {
            throw new RuntimeException('Google did not grant offline access. Revoke the previous grant in Google and reconnect.');
        }
        DB::transaction(function () use ($user, $profile, $tokens, $scopes, $refresh): void {
            // A business connection is a singleton; never silently replace a different account/grant.
            if (GoogleConnection::query()->lockForUpdate()->find(1) !== null) {
                throw new RuntimeException('Disconnect the existing company account first.');
            }
            GoogleConnection::query()->create(['id' => 1, 'connected_by' => $user->id,
                'google_subject' => $profile['sub'], 'email' => $profile['email'],
                'access_token' => $tokens['access_token'], 'refresh_token' => $refresh,
                'scopes' => $scopes, 'expires_at' => now()->addSeconds($this->lifetime($tokens)),
                'connected_at' => now(), 'last_checked_at' => now(), 'status' => 'active']);
            $this->audit('business.connected', $user->id);
        });
    }

    /** @param array{sub: string, email: string} $profile */
    public function link(User $user, array $profile): void
    {
        abort_unless($user->is_active, 403);
        DB::transaction(function () use ($user, $profile): void {
            if (GoogleIdentity::query()->where('user_id', $user->id)->exists()
                || GoogleIdentity::query()->where('google_subject', $profile['sub'])->exists()) {
                throw new RuntimeException('Unlink the existing identity first.');
            }
            GoogleIdentity::query()->create(['user_id' => $user->id, 'google_subject' => $profile['sub'], 'email' => $profile['email']]);
            $this->audit('identity.linked', $user->id);
        });
    }

    public function unlink(User $user): void
    {
        DB::transaction(function () use ($user): void {
            GoogleIdentity::query()->where('user_id', $user->id)->delete();
            $this->audit('identity.unlinked', $user->id);
        });
    }

    public function disconnect(User $user): bool
    {
        $this->authorizeOwner($user);
        $connection = GoogleConnection::query()->find(1);
        if ($connection === null) {
            return true;
        }
        $connection->update(['status' => 'revocation_pending']);
        try {
            $revoked = $this->google->revoke($connection->refresh_token ?: $connection->access_token);
        } catch (Throwable) {
            $revoked = false;
        }
        if ($revoked) {
            DB::transaction(function () use ($connection, $user): void {
                $connection->delete();
                $this->audit('business.disconnected', $user->id);
            });
        } else {
            $this->audit('business.revocation_failed', $user->id, false);
        }

        return $revoked;
    }

    public function check(User $user): bool
    {
        $this->authorizeOwner($user);
        $connection = GoogleConnection::query()->findOrFail(1);
        if ($connection->status === 'revocation_pending') {
            return false;
        }
        try {
            $tokens = ['access_token' => $connection->access_token, 'token_type' => 'Bearer'];
            if ($connection->expires_at->lte(now()->addMinute())) {
                $tokens = $this->google->refresh((string) $connection->refresh_token);
            }
            $profile = $this->verifiedProfile($tokens);
            if ($profile['sub'] !== $connection->google_subject) {
                throw new RuntimeException('Account changed.');
            }
            $connection->update(['access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? $connection->refresh_token,
                'expires_at' => isset($tokens['expires_in']) ? now()->addSeconds($this->lifetime($tokens)) : $connection->expires_at,
                'email' => $profile['email'], 'last_checked_at' => now(), 'status' => 'active']);
            $this->audit('business.checked', $user->id);

            return true;
        } catch (Throwable) {
            $connection->update(['status' => 'reconnect_required']);
            $this->audit('business.check_failed', $user->id, false);

            return false;
        }
    }

    public function audit(string $action, ?int $userId, bool $ok = true): void
    {
        IntegrationLog::query()->create(['integration' => 'google', 'direction' => 'in', 'action' => $action,
            'ok' => $ok, 'request' => ['user_id' => $userId], 'created_at' => now()]);
    }

    /** @param array<string, mixed> $tokens */
    private function lifetime(array $tokens): int
    {
        return max(60, min(86400, (int) ($tokens['expires_in'] ?? 3600)));
    }
}
