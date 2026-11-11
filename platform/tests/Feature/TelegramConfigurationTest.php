<?php

use App\Integrations\Telegram\Fakes\FakeTelegramGateway;
use App\Integrations\Telegram\TelegramConfiguration;
use App\Livewire\Admin\System\Integrations;
use App\Support\Install\SiteState;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->configurationHome = storage_path('framework/testing/telegram-config-'.bin2hex(random_bytes(5)));
    $this->configurationBase = $this->configurationHome.'/domains/site.test/public_html/platform';
    $this->configurationPrivate = $this->configurationHome.'/woodex-os/site.test';
    mkdir($this->configurationBase, 0777, true);
    mkdir($this->configurationPrivate, 0777, true);
});

afterEach(function () {
    File::deleteDirectory($this->configurationHome);
});

function telegramConfigurationProbe(object $test, string $path, bool $cached = false): TelegramConfiguration
{
    $app = Mockery::mock(Application::class);
    $app->shouldReceive('environmentFilePath')->andReturn($path);
    $app->shouldReceive('configurationIsCached')->andReturn($cached);

    return new TelegramConfiguration($app, new SiteState($test->configurationBase, $test->configurationHome));
}

it('detects saved credentials hidden by stale configuration without returning either secret', function () {
    $path = $this->configurationPrivate.'/.env';
    file_put_contents($path, "TELEGRAM_BOT_TOKEN=123:PRIVATE-TOKEN\nTELEGRAM_WEBHOOK_SECRET=PRIVATE-SECRET\nAPP_KEY=PRIVATE-APP-KEY\n");
    config(['services.telegram.bot_token' => null, 'services.telegram.webhook_secret' => null]);
    $status = telegramConfigurationProbe($this, $path, true)->inspect();

    expect($status['cached'])->toBeTrue()->and($status['token_loaded'])->toBeFalse()
        ->and($status['token_in_file'])->toBeTrue()->and($status['secret_in_file'])->toBeTrue()
        ->and($status['file_matches_runtime'])->toBeFalse()
        ->and($status['path'])->toBe('woodex-os/site.test/.env')
        ->and(json_encode($status))->not->toContain('PRIVATE');
});

it('shows when a classic env is selected instead of the private env without changing either file', function () {
    $classic = $this->configurationBase.'/.env';
    $private = $this->configurationPrivate.'/.env';
    file_put_contents($classic, "APP_NAME=Classic\n");
    file_put_contents($private, "TELEGRAM_BOT_TOKEN=123:PRIVATE-TOKEN\nTELEGRAM_WEBHOOK_SECRET=PRIVATE-SECRET\n");
    $status = telegramConfigurationProbe($this, $classic)->inspect();

    expect($status['path'])->toBe('domains/site.test/public_html/platform/.env')
        ->and($status['alternate_path'])->toBe('woodex-os/site.test/.env')
        ->and($status['token_in_file'])->toBeFalse()
        ->and(file_get_contents($classic))->toBe("APP_NAME=Classic\n")
        ->and(file_get_contents($private))->toContain('123:PRIVATE-TOKEN');
});

it('reports malformed env files without exposing parser errors or credential lines', function () {
    $path = $this->configurationPrivate.'/.env';
    file_put_contents($path, "TELEGRAM_BOT_TOKEN=PRIVATE TOKEN\n");
    $status = telegramConfigurationProbe($this, $path)->inspect();
    expect($status['file_status'])->toBe('invalid')->and($status['file_matches_runtime'])->toBeNull()
        ->and(json_encode($status))->not->toContain('PRIVATE');
});

it('reports unreadable files and null-valued settings as missing', function () {
    $path = $this->configurationPrivate.'/.env';
    expect(telegramConfigurationProbe($this, $path)->inspect()['file_status'])->toBe('unreadable');
    file_put_contents($path, "TELEGRAM_BOT_TOKEN=null\nTELEGRAM_WEBHOOK_SECRET=\n");
    $status = telegramConfigurationProbe($this, $path)->inspect();
    expect($status['token_in_file'])->toBeFalse()->and($status['secret_in_file'])->toBeFalse();
});

it('opens secret-free server diagnostics automatically when webhook credentials are missing', function () {
    $path = $this->configurationPrivate.'/.env';
    file_put_contents($path, "TELEGRAM_BOT_TOKEN=123:PRIVATE-TOKEN\nTELEGRAM_WEBHOOK_SECRET=PRIVATE-SECRET\n");
    $this->app->instance(TelegramConfiguration::class, telegramConfigurationProbe($this, $path, true));
    config(['services.telegram.bot_token' => null, 'services.telegram.webhook_secret' => null]);
    FakeTelegramGateway::bind();
    $this->actingAs($this->userWithRole('owner'));
    Http::preventStrayRequests();

    Livewire::test(Integrations::class)->call('setTelegramWebhook')
        ->assertSet('showTelegramConfiguration', true)
        ->assertSee('Active settings file:')->assertSee('woodex-os/site.test/.env')
        ->assertSee('Saved values differ')->assertSee('Reload server configuration')
        ->assertDontSee('PRIVATE-TOKEN')->assertDontSee('PRIVATE-SECRET');
});

it('clears only configuration cache and redirects for a fresh request', function () {
    $path = $this->configurationPrivate.'/.env';
    $contents = "TELEGRAM_BOT_TOKEN=123:PRIVATE-TOKEN\nTELEGRAM_WEBHOOK_SECRET=PRIVATE-SECRET\n";
    file_put_contents($path, $contents);
    $this->actingAs($this->userWithRole('owner'));
    Artisan::shouldReceive('call')->once()->with('config:clear')->andReturn(0);

    Livewire::test(Integrations::class)->call('reloadTelegramConfiguration')
        ->assertRedirect(route('admin.integrations'));
    expect(file_get_contents($path))->toBe($contents);
    expect(session('status'))->toContain('configuration cache cleared');
});

it('reports cache clear failure without echoing exception contents', function () {
    $this->actingAs($this->userWithRole('owner'));
    Artisan::shouldReceive('call')->with('config:clear')->once()->andThrow(new RuntimeException('PRIVATE-TOKEN'));
    Livewire::test(Integrations::class)->call('reloadTelegramConfiguration')
        ->assertDispatched('toast', type: 'error')->assertNoRedirect()->assertDontSee('PRIVATE-TOKEN');
});

it('keeps env diagnostics and cache maintenance restricted to owners', function () {
    $this->actingAs($this->userWithRole('admin'));
    Artisan::shouldReceive('call')->never();
    Livewire::test(Integrations::class)->assertDontSee('Check server configuration')
        ->call('checkTelegramConfiguration')->assertForbidden();
    Livewire::test(Integrations::class)->call('reloadTelegramConfiguration')->assertForbidden();
});
