<?php

declare(strict_types=1);

namespace App\Integrations\Connectors;

use App\Domain\System\Models\IntegrationLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The one place a connection is created, re-checked or removed.
 *
 * Every transition writes an `integration_logs` row, so the hub's history is the same record the rest of the
 * system uses. A provider is never stored on the strength of a saved flag: `connect()` and `check()` both call
 * the provider first.
 */
final class ConnectorManager
{
    public function __construct(private readonly ConnectorRegistry $registry) {}

    /** @return list<Connector> */
    public function connectors(): array
    {
        return $this->registry->all();
    }

    public function connector(string $key): Connector
    {
        return $this->registry->get($key);
    }

    public function connection(string $key): ?IntegrationConnection
    {
        return IntegrationConnection::query()->where('provider', $key)->first();
    }

    /**
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function connectWithToken(string $key, array $input, User $user): IntegrationConnection
    {
        $connector = $this->connector($key);
        $result = $connector->connect($input, $user);

        return $this->store($connector, $result, $user, 'connect');
    }

    public function authorizeUrl(string $key, User $user): string
    {
        return $this->connector($key)->authorizeUrl(OAuthState::issue($key, $user->id));
    }

    /**
     * @throws ValidationException
     */
    public function completeAuthorization(string $key, string $code, User $user): IntegrationConnection
    {
        $connector = $this->connector($key);
        $result = $connector->complete($code, $user);

        return $this->store($connector, $result, $user, 'connect');
    }

    /**
     * Ask the provider now, and record the answer.
     *
     * @return array{ok: bool, account: string|null, detail: string, error: string|null}
     */
    public function check(string $key): array
    {
        $connector = $this->connector($key);
        $connection = $this->connection($key);

        if ($connection === null) {
            return ['ok' => false, 'account' => null, 'detail' => '', 'error' => 'This provider is not connected.'];
        }

        $result = $this->probe($connector, $connection->credentials);

        $connection->forceFill([
            'status' => $result['ok'] ? 'connected' : 'error',
            'account' => $result['account'] ?? $connection->account,
            'scopes' => $result['scopes'] ?? $connection->scopes,
            'meta' => $result['meta'] !== [] ? $result['meta'] : $connection->meta,
            'last_checked_at' => now(),
            'last_error' => $result['ok'] ? null : $result['error'],
        ])->save();

        return $result;
    }

    public function disconnect(string $key): bool
    {
        $connector = $this->connector($key);
        $connection = $this->connection($key);

        if ($connection === null) {
            return false;
        }

        try {
            $connector->disconnect($connection->credentials);
        } catch (Throwable) {
            // Deleting our copy matters more than the provider's answer.
        }

        $connection->delete();
        $this->log($key, 'disconnect', true, null, null);

        return true;
    }

    /**
     * @param  array{credentials: array<string, mixed>, account: string, scopes: list<string>, meta: array<string, mixed>}  $result
     */
    private function store(Connector $connector, array $result, User $user, string $action): IntegrationConnection
    {
        // A recognition hint only; the complete credential remains encrypted and hidden.
        $token = $result['credentials']['token'] ?? null;
        if (is_string($token) && strlen($token) >= 8) {
            $result['meta']['credential_hint'] = '••••'.substr($token, -4);
        }

        $connection = DB::transaction(fn (): IntegrationConnection => IntegrationConnection::query()->updateOrCreate(
            ['provider' => $connector->key()],
            [
                'status' => 'connected',
                'account' => mb_substr($result['account'], 0, 191),
                'credentials' => $result['credentials'],
                'scopes' => $result['scopes'],
                'meta' => $result['meta'],
                'connected_by' => $user->id,
                'connected_at' => now(),
                'last_checked_at' => now(),
                'last_error' => null,
            ],
        ));

        $this->log($connector->key(), $action, true, null, $result['account']);

        return $connection;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{ok: bool, account: string|null, detail: string, scopes: list<string>|null, meta: array<string, mixed>, error: string|null}
     */
    private function probe(Connector $connector, array $credentials): array
    {
        $started = microtime(true);

        try {
            $result = $connector->probe($credentials);
        } catch (Throwable $exception) {
            $result = ['ok' => false, 'account' => null, 'detail' => '', 'scopes' => null, 'meta' => [],
                'error' => 'The provider could not be reached. Check the service status and try again.'];
        }

        IntegrationLog::query()->create([
            'integration' => $connector->key(), 'direction' => 'out', 'action' => 'probe',
            'ok' => $result['ok'], 'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'request' => ['account' => $result['account']], 'error' => $result['error'], 'created_at' => now(),
        ]);

        return $result;
    }

    private function log(string $provider, string $action, bool $ok, ?string $error, ?string $account): void
    {
        IntegrationLog::query()->create([
            'integration' => $provider, 'direction' => 'out', 'action' => $action, 'ok' => $ok,
            'request' => ['account' => $account], 'error' => $error, 'created_at' => now(),
        ]);
    }
}
