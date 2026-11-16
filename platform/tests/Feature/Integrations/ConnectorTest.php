<?php

use App\Domain\System\Models\IntegrationLog;
use App\Integrations\Connectors\ConnectorManager;
use App\Integrations\Connectors\IntegrationConnection;
use App\Integrations\Connectors\OAuthState;
use App\Integrations\Support\PublicHostResolver;
use App\Livewire\Admin\System\Integrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->app->instance(PublicHostResolver::class, new class extends PublicHostResolver
    {
        public function addresses(string $host): array
        {
            return ['8.8.8.8'];
        }
    });
});

function githubOk(): void
{
    Http::fake([
        'api.github.com/user' => Http::response([
            'login' => 'woodex420', 'name' => 'Woodex', 'public_repos' => 12,
        ], 200, ['X-RateLimit-Remaining' => '4999', 'X-OAuth-Scopes' => 'repo, read:user']),
    ]);
}

it('proves a github token before storing it and encrypts it at rest', function () {
    githubOk();
    $this->actingAs($this->userWithRole('owner'));

    Livewire::test(Integrations::class)
        ->call('openConnector', 'github')
        ->assertSet('showConnectorModal', true)
        ->set('connectorInput.token', 'ghp_secret-token-value')
        ->call('connectConnector')
        ->assertHasNoErrors()
        ->assertSet('showConnectorModal', false)
        ->assertSee('woodex420');

    $connection = IntegrationConnection::query()->sole();
    expect($connection->provider)->toBe('github')
        ->and($connection->account)->toBe('woodex420')
        ->and($connection->status)->toBe('connected')
        ->and($connection->scopes)->toBe(['repo', 'read:user'])
        ->and($connection->toArray())->not->toHaveKey('credentials')
        ->and(DB::table('integration_connections')->value('credentials'))->not->toContain('ghp_secret-token-value')
        ->and(IntegrationLog::query()->where('integration', 'github')->where('action', 'connect')->exists())->toBeTrue();
});

it('refuses a token the provider rejects and stores nothing', function () {
    Http::fake(['api.github.com/user' => Http::response(['message' => 'Bad credentials'], 401)]);
    $this->actingAs($this->userWithRole('owner'));

    Livewire::test(Integrations::class)
        ->call('openConnector', 'github')
        ->set('connectorInput.token', 'ghp_wrong')
        ->call('connectConnector')
        ->assertHasErrors('connectorInput');

    expect(IntegrationConnection::query()->count())->toBe(0);
});

it('checks a connection live and records the failure instead of pretending', function () {
    $this->actingAs($this->userWithRole('owner'));
    Http::fake(['api.github.com/user' => Http::sequence()
        ->push(['login' => 'woodex420', 'public_repos' => 12], 200, ['X-RateLimit-Remaining' => '4999'])
        ->push(['message' => 'Bad credentials'], 401)]);

    Livewire::test(Integrations::class)->call('openConnector', 'github')
        ->set('connectorInput.token', 'ghp_secret')->call('connectConnector');

    Livewire::test(Integrations::class)->call('checkConnector', 'github')->assertSee('GitHub rejected this token');

    $connection = IntegrationConnection::query()->sole();
    expect($connection->status)->toBe('error')
        ->and($connection->last_error)->toContain('401')
        ->and(IntegrationLog::query()->where('integration', 'github')->where('action', 'probe')->where('ok', false)->exists())->toBeTrue();
});

it('disconnect deletes the stored credential', function () {
    githubOk();
    $this->actingAs($this->userWithRole('owner'));
    Livewire::test(Integrations::class)->call('openConnector', 'github')
        ->set('connectorInput.token', 'ghp_secret')->call('connectConnector');

    Livewire::test(Integrations::class)->call('disconnectConnector', 'github');

    expect(IntegrationConnection::query()->count())->toBe(0)
        ->and(IntegrationLog::query()->where('integration', 'github')->where('action', 'disconnect')->exists())->toBeTrue();
});

it('keeps connector authorisation owner-only', function () {
    $this->actingAs($this->userWithRole('admin'));

    Livewire::test(Integrations::class)->call('openConnector', 'github')->assertForbidden();

    expect(IntegrationConnection::query()->count())->toBe(0);
});

it('builds a real google consent url with the workspace scopes and a signed state', function () {
    config()->set('services.google.client_id', 'client-id');
    config()->set('services.google.client_secret', 'client-secret');
    config()->set('services.google.workspace_redirect_uri', 'https://woodex.test/admin/integrations/oauth/google/callback');
    $this->actingAs($this->userWithRole('owner'));

    $response = $this->get(route('admin.integrations.oauth.start', ['provider' => 'google_workspace']))->assertRedirect();

    $target = $response->headers->get('Location');
    expect($target)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')
        ->toContain('access_type=offline')
        ->toContain(rawurlencode('https://www.googleapis.com/auth/drive.metadata.readonly'))
        ->toContain('state=');

    parse_str((string) parse_url((string) $target, PHP_URL_QUERY), $query);
    expect(OAuthState::pull((string) $query['state'])['provider'])->toBe('google_workspace');
});

it('completes the google round trip and stores only the scopes google reports', function () {
    config()->set('services.google.client_id', 'client-id');
    config()->set('services.google.client_secret', 'client-secret');
    config()->set('services.google.workspace_redirect_uri', 'https://woodex.test/admin/integrations/oauth/google/callback');

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'ya29.access', 'refresh_token' => '1//refresh', 'expires_in' => 3600, 'token_type' => 'Bearer',
        ]),
        'oauth2.googleapis.com/tokeninfo*' => Http::response([
            'aud' => 'client-id', 'email' => 'studio@woodex.com.pk',
            'scope' => 'openid email https://www.googleapis.com/auth/drive.metadata.readonly',
        ]),
    ]);

    $owner = $this->userWithRole('owner');
    $this->actingAs($owner);
    $state = OAuthState::issue('google_workspace', $owner->id);

    $this->get(route('admin.integrations.oauth.callback', ['provider' => 'google_workspace', 'code' => 'code-1', 'state' => $state]))
        ->assertRedirect(route('admin.integrations'));

    $connection = IntegrationConnection::query()->sole();
    expect($connection->account)->toBe('studio@woodex.com.pk')
        ->and($connection->scopes)->toBe(['openid', 'email', 'https://www.googleapis.com/auth/drive.metadata.readonly'])
        ->and($connection->credentials['refresh_token'])->toBe('1//refresh')
        ->and(DB::table('integration_connections')->value('credentials'))->not->toContain('1//refresh');
});

it('rejects a forged or expired oauth state', function () {
    config()->set('services.google.client_id', 'client-id');
    config()->set('services.google.client_secret', 'client-secret');
    config()->set('services.google.workspace_redirect_uri', 'https://woodex.test/admin/integrations/oauth/google/callback');
    $this->actingAs($this->userWithRole('owner'));

    $this->get(route('admin.integrations.oauth.callback', ['provider' => 'google_workspace', 'code' => 'x', 'state' => 'forged']))
        ->assertRedirect(route('admin.integrations'))
        ->assertSessionHas('connector_error');

    expect(IntegrationConnection::query()->count())->toBe(0);
});

it('connects any https api and treats a provider-reported failure as a failure', function () {
    Http::fake(['api.client.test/health' => Http::sequence()
        ->push(['ok' => true], 200)
        ->push(['ok' => false, 'error' => 'invalid_auth'], 200)]);
    $this->actingAs($this->userWithRole('owner'));

    Livewire::test(Integrations::class)->call('openConnector', 'custom_api')
        ->set('connectorInput.label', 'Client API')
        ->set('connectorInput.base_url', 'https://api.client.test/health')
        ->set('connectorInput.token', 'token-123')
        ->call('connectConnector')->assertHasNoErrors();

    expect(IntegrationConnection::query()->sole()->account)->toBe('api.client.test');

    $result = app(ConnectorManager::class)->check('custom_api');

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('invalid_auth');
});

it('refuses private or plaintext urls for a custom connector', function (string $url) {
    $this->actingAs($this->userWithRole('owner'));

    Livewire::test(Integrations::class)->call('openConnector', 'custom_api')
        ->set('connectorInput.label', 'Internal')
        ->set('connectorInput.base_url', $url)
        ->set('connectorInput.token', 'token')
        ->call('connectConnector')->assertHasErrors('connectorInput');

    expect(IntegrationConnection::query()->count())->toBe(0);
})->with([
    'http://api.client.test/health',
    'https://127.0.0.1/health',
    'https://localhost/health',
    'https://10.0.0.5/health',
]);

it('checks every authorised connector at once', function () {
    Http::fake(['api.github.com/user' => Http::sequence()
        ->push(['login' => 'woodex420', 'public_repos' => 12], 200, ['X-RateLimit-Remaining' => '4999'])
        ->push(['login' => 'woodex420', 'public_repos' => 12], 200, ['X-RateLimit-Remaining' => '4998'])]);
    $this->actingAs($this->userWithRole('owner'));
    Livewire::test(Integrations::class)->call('openConnector', 'github')
        ->set('connectorInput.token', 'ghp_secret')->call('connectConnector');

    githubOk();

    Livewire::test(Integrations::class)->call('checkAllConnectors');

    expect(IntegrationConnection::query()->sole()->last_checked_at->isAfter(now()->subMinute()))->toBeTrue();
});
