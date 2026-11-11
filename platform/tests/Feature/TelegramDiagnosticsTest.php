<?php

use App\Domain\System\Models\IntegrationLog;
use App\Integrations\Telegram\Clients\TelegramBotApi;
use App\Integrations\Telegram\Fakes\FakeTelegramGateway;
use App\Livewire\Admin\System\Integrations;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('checks bot identity and webhook delivery without exposing secrets or chat data', function () {
    $token = '123:TEST-PRIVATE';
    $url = 'https://woodex.test/webhooks/telegram/private-hook';
    Http::fake([
        '*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'OtherBot']]),
        '*/getWebhookInfo' => Http::response(['ok' => true, 'result' => [
            'url' => 'https://old.test/webhooks/telegram/private-hook',
            'pending_update_count' => 6,
            'last_error_date' => 1700000000,
            'last_error_message' => 'Wrong response from '.$url.' with '.$token.': 404 Not Found',
        ]]),
    ]);

    $status = (new TelegramBotApi($token))->connectionStatus($url, 'WoodexInteriorBot');
    expect($status['api_ok'])->toBeTrue()
        ->and($status['bot_matches'])->toBeFalse()
        ->and($status['webhook_matches'])->toBeFalse()
        ->and($status['pending_updates'])->toBe(6)
        ->and($status['last_error'])->toContain('404 Not Found')
        ->and(json_encode($status))->not->toContain($token)->not->toContain('private-hook');
    expect(IntegrationLog::query()->where('integration', 'telegram')->count())->toBe(2);
    expect(IntegrationLog::query()->get()->toJson())->not->toContain($token)->not->toContain('private-hook');
});

it('reports failed bot API authentication and empty webhook registration', function () {
    Http::fake([
        '*/getMe' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401),
        '*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => '', 'pending_update_count' => 0]]),
    ]);
    $status = (new TelegramBotApi('bad-token'))->connectionStatus('https://woodex.test/webhooks/telegram/secret', 'WoodexInteriorBot');
    expect($status['api_ok'])->toBeFalse()->and($status['webhook_set'])->toBeFalse();
});

it('registers a webhook without discarding pending user messages', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);
    expect((new TelegramBotApi('test-token'))->setWebhook('https://woodex.test/webhooks/telegram/secret', 'secret'))->toBeTrue();
    Http::assertSent(fn ($request) => $request['drop_pending_updates'] === false);
});

it('shows runtime diagnostics to administrators with no false connected claim', function () {
    config(['services.telegram.bot_token' => 'test-token', 'services.telegram.webhook_secret' => 'test-secret']);
    $fake = FakeTelegramGateway::bind();
    $fake->diagnostics['pending_updates'] = 9;
    $fake->diagnostics['last_error'] = 'Wrong response from the webhook: 404 Not Found';
    $this->actingAs($this->userWithRole('owner'));
    Livewire::test(Integrations::class)->assertSee('Configured')
        ->call('checkTelegramConnection')->assertSee('@WoodexInteriorBot')
        ->assertSee('Pending updates: 9')->assertSee('404 Not Found')
        ->assertSee('Last received here: none')->assertDontSee('test-secret');
});

it('refuses diagnostics for staff without integration management permission', function () {
    $this->actingAs($this->userWithRole('sales'));
    Livewire::test(Integrations::class)->assertForbidden();
});
