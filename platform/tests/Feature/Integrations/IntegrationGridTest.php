<?php

declare(strict_types=1);

use App\Domain\System\Models\IntegrationLog;
use App\Integrations\Connectors\ConnectorManager;
use App\Integrations\Connectors\IntegrationConnection;
use App\Livewire\Admin\System\Integrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs($this->userWithRole('owner'));
});

it('lists providers once, filters all of them, and exposes working WhatsApp actions', function () {
    $screen = Livewire::test(Integrations::class)->assertDontSee('Planned')
        ->assertSee('Test connection')->assertSee('Add any app')
        ->assertViewHas('providers', fn ($providers) => count(array_column($providers, 'key')) === count(array_unique(array_column($providers, 'key'))))
        ->set('appCategory', 'communication')->assertSee('Telegram bot')->assertDontSee('GitHub')
        ->set('appCategory', 'all')->set('appSearch', 'GitHub')->assertSee('GitHub')->assertDontSee('Telegram bot')
        ->call('openDetails', 'github')->assertSee('Developer settings');
});

it('uses real log ordering rather than a saved configured flag', function () {
    config(['services.telegram.bot_token' => 'private-test-token']);
    IntegrationLog::query()->create(['integration' => 'telegram', 'action' => 'send', 'ok' => true, 'created_at' => now()->subHour()]);
    IntegrationLog::query()->create(['integration' => 'telegram', 'action' => 'send', 'ok' => false, 'error' => 'Bot blocked', 'created_at' => now()]);
    Livewire::test(Integrations::class)->assertViewHas('providers', fn ($providers) => collect($providers)->firstWhere('key', 'telegram')['state'] === 'error')
        ->assertDontSee('private-test-token');
});

it('proves and encrypts a Facebook Page token without claiming publishing works', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '12345', 'name' => 'Woodex Page', 'fan_count' => 1250])]);
    Livewire::test(Integrations::class)->call('openConnector', 'facebook_page')
        ->set('connectorInput.page_id', '12345')->set('connectorInput.token', 'EAAB-private-test')
        ->call('connectConnector')->assertHasNoErrors()->assertSee('Woodex Page');
    $connection = IntegrationConnection::query()->sole();
    expect($connection->provider)->toBe('facebook_page')->and($connection->meta['followers'])->toBe(1250)
        ->and(DB::table('integration_connections')->value('credentials'))->not->toContain('EAAB-private-test');
    Livewire::test(Integrations::class)->call('openDetails', 'facebook_page')->assertSee('Posting itself still needs Meta app approval');
});

it('rejects a refused Facebook Page token and records a later failed check', function () {
    Http::fake(['graph.facebook.com/*' => Http::sequence()->push(['error' => ['message' => 'Invalid token']], 401)
        ->push(['id' => '12345', 'name' => 'Woodex Page'])->push(['error' => ['message' => 'Expired token']], 401)]);
    Livewire::test(Integrations::class)->call('openConnector', 'facebook_page')
        ->set('connectorInput.page_id', '12345')->set('connectorInput.token', 'EAAB-private-test')
        ->call('connectConnector')->assertHasErrors('connectorInput');
    expect(IntegrationConnection::query()->count())->toBe(0);
    $manager = app(ConnectorManager::class);
    $manager->connectWithToken('facebook_page', ['page_id' => '12345', 'token' => 'EAAB-private-test'], auth()->user());
    $result = $manager->check('facebook_page');
    expect($result['ok'])->toBeFalse()->and(IntegrationConnection::query()->sole()->status)->toBe('error');
});

it('refuses a Facebook token whose identity does not match the requested Page ID', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '98765', 'name' => 'Different account'])]);
    Livewire::test(Integrations::class)->call('openConnector', 'facebook_page')
        ->set('connectorInput.page_id', '12345')->set('connectorInput.token', 'EAAB-private-test')
        ->call('connectConnector')->assertHasErrors('connectorInput');
    expect(IntegrationConnection::query()->count())->toBe(0);
});
