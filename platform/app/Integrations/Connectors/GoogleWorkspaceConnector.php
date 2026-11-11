<?php

declare(strict_types=1);

namespace App\Integrations\Connectors;

use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A real Google Workspace connection: Drive, Sheets, Gmail and Calendar read scopes on one company account.
 *
 * This is deliberately separate from `App\Integrations\Google` (identity sign-in, `openid email profile` only).
 * That connection must never gain data access by accident, so the two live in different rows with different
 * tokens and different scopes.
 *
 * The callback exchanges the code for tokens, then proves the token against Google's tokeninfo endpoint — the
 * scopes stored are the ones Google reports, not the ones we asked for.
 */
final class GoogleWorkspaceConnector extends Connector
{
    private const string AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const string TOKEN = 'https://oauth2.googleapis.com/token';

    private const string REVOKE = 'https://oauth2.googleapis.com/revoke';

    private const string TOKENINFO = 'https://oauth2.googleapis.com/tokeninfo';

    public function key(): string
    {
        return 'google_workspace';
    }

    public function name(): string
    {
        return 'Google Workspace';
    }

    public function mode(): string
    {
        return 'oauth';
    }

    public function icon(): string
    {
        return 'globe';
    }

    public function detail(): string
    {
        return 'Drive, Sheets, Gmail and Calendar — read-only, on a company account.';
    }

    public function missingRequirement(): ?string
    {
        if (! self::configured()) {
            return 'Add GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET to the server environment, then reload the configuration.';
        }

        return null;
    }

    public static function configured(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'))
            && filter_var((string) config('services.google.workspace_redirect_uri'), FILTER_VALIDATE_URL) !== false;
    }

    /** @return list<string> */
    public static function scopes(): array
    {
        $scopes = config('services.google.workspace_scopes', []);

        return is_array($scopes) ? array_values(array_filter(array_map('strval', $scopes))) : [];
    }

    public function authorizeUrl(string $state): string
    {
        if (! self::configured()) {
            throw ValidationException::withMessages(['connect' => (string) $this->missingRequirement()]);
        }

        return self::AUTH.'?'.http_build_query([
            'client_id' => (string) config('services.google.client_id'),
            'redirect_uri' => (string) config('services.google.workspace_redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', self::scopes()),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function complete(string $code, User $user): array
    {
        if (! self::configured()) {
            throw ValidationException::withMessages(['connect' => (string) $this->missingRequirement()]);
        }

        $response = Http::asForm()->acceptJson()->timeout(15)->connectTimeout(5)->withoutRedirecting()
            ->post(self::TOKEN, [
                'grant_type' => 'authorization_code', 'code' => $code,
                'client_id' => (string) config('services.google.client_id'),
                'client_secret' => (string) config('services.google.client_secret'),
                'redirect_uri' => (string) config('services.google.workspace_redirect_uri'),
            ]);

        $data = $this->json($response);

        if (! $response->successful() || ! is_string($data['access_token'] ?? null)) {
            throw ValidationException::withMessages(['connect' => 'Google refused the authorization code. Start the connection again.']);
        }

        if (! is_string($data['refresh_token'] ?? null) || $data['refresh_token'] === '') {
            throw ValidationException::withMessages(['connect' => 'Google returned no refresh token. Remove Woodex from the Google account permissions and connect again.']);
        }

        $credentials = [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'expires_at' => Carbon::now()->addSeconds((int) ($data['expires_in'] ?? 3600))->toIso8601String(),
            'token_type' => is_string($data['token_type'] ?? null) ? $data['token_type'] : 'Bearer',
        ];

        $probe = $this->probe($credentials);

        if (! $probe['ok']) {
            throw ValidationException::withMessages(['connect' => (string) $probe['error']]);
        }

        return [
            'credentials' => $credentials,
            'account' => (string) $probe['account'],
            'scopes' => $probe['scopes'] ?? [],
            'meta' => $probe['meta'],
        ];
    }

    /** @param array<string, mixed> $credentials */
    public function probe(array $credentials): array
    {
        $empty = ['ok' => false, 'account' => null, 'detail' => '', 'scopes' => null, 'meta' => [], 'error' => 'No Google token is stored.'];

        if (! self::configured()) {
            return [...$empty, 'error' => 'Google app credentials are not configured on this server.'];
        }

        try {
            $access = $this->freshAccessToken($credentials);
        } catch (Throwable $exception) {
            return [...$empty, 'error' => $exception->getMessage()];
        }

        if ($access === '') {
            return [...$empty, 'error' => 'The Google refresh token is missing or expired. Connect again.'];
        }

        $response = Http::acceptJson()->timeout(10)->connectTimeout(5)->withoutRedirecting()
            ->get(self::TOKENINFO, ['access_token' => $access]);

        if (! $response->successful()) {
            return [...$empty, 'error' => 'Google rejected the access token ('.$response->status().'). Connect again.'];
        }

        $data = $this->json($response);
        $audience = is_string($data['aud'] ?? null) ? $data['aud'] : '';

        if ($audience !== (string) config('services.google.client_id')) {
            return [...$empty, 'error' => 'The token belongs to a different Google app. Connect again.'];
        }

        $scopes = preg_split('/\s+/', trim((string) ($data['scope'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $email = is_string($data['email'] ?? null) ? $data['email'] : 'company account';
        $granted = array_values(array_filter($scopes, static fn (string $scope): bool => ! in_array($scope, ['openid', 'email'], true)));

        return [
            'ok' => true,
            'account' => $email,
            'detail' => count($granted).' read '.((count($granted) === 1) ? 'scope' : 'scopes').' granted',
            'scopes' => $scopes,
            'meta' => ['email' => $email, 'granted' => $granted],
            'error' => null,
        ];
    }

    /** @param array<string, mixed> $credentials */
    public function disconnect(array $credentials): void
    {
        $token = (string) ($credentials['refresh_token'] ?? $credentials['access_token'] ?? '');

        if ($token === '') {
            return;
        }

        try {
            Http::asForm()->timeout(8)->connectTimeout(4)->withoutRedirecting()->post(self::REVOKE, ['token' => $token]);
        } catch (Throwable) {
            // The local row is deleted either way; a provider outage must not trap a token on this server.
        }
    }

    /** @param array<string, mixed> $credentials
     * @throws ValidationException
     */
    private function freshAccessToken(array $credentials): string
    {
        $access = is_string($credentials['access_token'] ?? null) ? $credentials['access_token'] : '';
        $expires = is_string($credentials['expires_at'] ?? null) ? Carbon::parse($credentials['expires_at']) : null;

        if ($access !== '' && ($expires === null || $expires->isAfter(Carbon::now()->addMinute()))) {
            return $access;
        }

        $refresh = is_string($credentials['refresh_token'] ?? null) ? $credentials['refresh_token'] : '';

        if ($refresh === '') {
            return $access;
        }

        $response = Http::asForm()->acceptJson()->timeout(15)->connectTimeout(5)->withoutRedirecting()
            ->post(self::TOKEN, [
                'grant_type' => 'refresh_token', 'refresh_token' => $refresh,
                'client_id' => (string) config('services.google.client_id'),
                'client_secret' => (string) config('services.google.client_secret'),
            ]);

        $data = $this->json($response);

        if (! $response->successful() || ! is_string($data['access_token'] ?? null)) {
            throw ValidationException::withMessages(['connect' => 'Google would not refresh the stored token. Connect again.']);
        }

        return $data['access_token'];
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        $data = $response->json();

        return is_array($data) ? $data : [];
    }
}
