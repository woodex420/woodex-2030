<?php

use App\Integrations\WhatsApp\Actions\TestWhatsAppConnection;
use App\Integrations\WhatsApp\Contracts\WhatsAppGateway;
use App\Integrations\WhatsApp\Fakes\FakeWhatsAppGateway;
use App\Integrations\WhatsApp\WhatsAppCredentials;
use App\Livewire\Admin\System\Integrations;
use App\Livewire\Admin\System\SettingsPage;
use App\Models\User;
use App\Settings\IntegrationSettings;
use Illuminate\Support\Facades\DB;

/*
| WAP-001: the studio connects WhatsApp itself.
|
| Credentials used to exist only in the server .env, which meant the owner could not connect WhatsApp at all on
| shared hosting. They are settings now, encrypted at rest, with the .env kept as a fallback — and the two rules
| that make that safe are the ones under test: a saved value wins over the .env, and a secret is never sent to
| the browser (which for Livewire means never put into a public property).
*/

beforeEach(function () {
    config()->set('services.whatsapp', [
        'token' => 'env-token',
        'phone_number_id' => '111111111111111',
        'waba_id' => '222222222222222',
        'app_secret' => 'env-secret',
        'verify_token' => 'env-verify',
        'api_version' => 'v23.0',
        'api_base' => 'https://graph.facebook.com',
        'quotation_template' => 'quotation',
    ]);
});

function whatsappCredentials(): WhatsAppCredentials
{
    // A fresh resolver per assertion: the class memoises what it read.
    return app(WhatsAppCredentials::class);
}

it('uses the server .env when nothing is saved in the dashboard', function () {
    expect(whatsappCredentials()->isConfigured())->toBeTrue()
        ->and(whatsappCredentials()->token())->toBe('env-token')
        ->and(whatsappCredentials()->source())->toBe('env');
});

it('lets credentials saved in the dashboard win over the .env', function () {
    $settings = app(IntegrationSettings::class);
    $settings->whatsapp_token = 'dashboard-token';
    $settings->whatsapp_phone_number_id = '999999999999999';
    $settings->save();

    expect(whatsappCredentials()->token())->toBe('dashboard-token')
        ->and(whatsappCredentials()->phoneNumberId())->toBe('999999999999999')
        ->and(whatsappCredentials()->source())->toBe('database');
});

it('keeps the .env value for a field the dashboard left empty', function () {
    $settings = app(IntegrationSettings::class);
    $settings->whatsapp_token = 'dashboard-token'; // the phone number id is left empty on purpose
    $settings->save();

    expect(whatsappCredentials()->token())->toBe('dashboard-token')
        ->and(whatsappCredentials()->phoneNumberId())->toBe('111111111111111');
});

it('never stores a secret in plain text', function () {
    $settings = app(IntegrationSettings::class);
    $settings->whatsapp_token = 'a-very-secret-token';
    $settings->whatsapp_app_secret = 'shhh';
    $settings->save();

    $payload = DB::table('settings')->where('group', 'integrations')->value('payload');

    expect($payload)->not->toContain('a-very-secret-token')
        ->and($payload)->not->toContain('shhh')
        // and it comes back intact for the code that needs it
        ->and(app(IntegrationSettings::class)->whatsapp_token)->toBe('a-very-secret-token')
        ->and(app(IntegrationSettings::class)->whatsapp_app_secret)->toBe('shhh');
});

it('saves what the studio typed and keeps a secret that was left blank', function () {
    $admin = $this->userWithRole('admin');

    $this->actingAs($admin)->get(route('admin.settings', ['tab' => 'whatsapp']))->assertOk();

    Livewire::actingAs($admin)->test(SettingsPage::class, ['tab' => 'whatsapp'])
        ->set('whatsapp.waba_id', '333333333333333')
        ->set('whatsapp.phone_number_id', '444444444444444')
        ->set('whatsapp.token', 'typed-token')
        ->call('saveWhatsApp')
        ->assertHasNoErrors();

    $settings = app(IntegrationSettings::class);

    expect($settings->whatsapp_token)->toBe('typed-token')
        ->and($settings->whatsapp_waba_id)->toBe('333333333333333');

    // Saving again without touching the token must not erase it.
    Livewire::actingAs($admin)->test(SettingsPage::class, ['tab' => 'whatsapp'])
        ->set('whatsapp.token', '')
        ->set('whatsapp.waba_id', '555555555555555')
        ->call('saveWhatsApp')
        ->assertHasNoErrors();

    expect(app(IntegrationSettings::class)->whatsapp_token)->toBe('typed-token')
        ->and(app(IntegrationSettings::class)->whatsapp_waba_id)->toBe('555555555555555');
});

it('never puts a saved secret into a livewire property', function () {
    $settings = app(IntegrationSettings::class);
    $settings->whatsapp_token = 'typed-token';
    $settings->save();

    $component = Livewire::actingAs($this->userWithRole('admin'))->test(SettingsPage::class, ['tab' => 'whatsapp']);

    expect($component->get('whatsapp.token'))->toBe('');

    // The masked hint is rendered for the human; the token itself is not in the payload.
    $html = $component->html();
    expect($html)->not->toContain('typed-token')->and($html)->toContain('••••');
});

it('rejects a nonsense phone number id', function () {
    Livewire::actingAs($this->userWithRole('admin'))->test(SettingsPage::class, ['tab' => 'whatsapp'])
        ->set('whatsapp.phone_number_id', 'not-a-number')
        ->call('saveWhatsApp')
        ->assertHasErrors('whatsapp.phone_number_id');
});

it('proves the connection against meta and remembers the number it found', function () {
    $gateway = new FakeWhatsAppGateway;
    $this->app->instance(WhatsAppGateway::class, $gateway);

    $result = app(TestWhatsAppConnection::class)->handle();

    expect($result['ok'])->toBeTrue()
        ->and($result['name'])->toBe('Woodex Interior')
        ->and($result['phone'])->toBe('+92 300 1234567')
        ->and($result['probe'])->toBeNull()
        ->and(app(IntegrationSettings::class)->whatsapp_verified_name)->toBe('Woodex Interior');
});

it('sends a real message when the studio gives a number, because valid credentials prove nothing', function () {
    $gateway = new FakeWhatsAppGateway;
    $this->app->instance(WhatsAppGateway::class, $gateway);

    $result = app(TestWhatsAppConnection::class)->handle('923001234567');

    expect($result['probe'])->toBeTrue()->and($gateway->sent)->toHaveCount(1);

    $failing = (new FakeWhatsAppGateway)->fails('Meta refused the message.');
    $this->app->instance(WhatsAppGateway::class, $failing);

    expect(app(TestWhatsAppConnection::class)->handle('923001234567')['probe'])->toBeFalse();
});

it('reports an unconnected whatsapp instead of pretending', function () {
    config()->set('services.whatsapp.token', null);

    expect(app(TestWhatsAppConnection::class)->handle()['ok'])->toBeFalse();
});

it('verifies meta\'s webhook handshake with the token saved in the dashboard', function () {
    $settings = app(IntegrationSettings::class);
    $settings->whatsapp_verify_token = 'our-own-string';
    $settings->save();

    $this->get(route('webhooks.whatsapp.verify', ['hub_mode' => 'subscribe', 'hub_verify_token' => 'our-own-string', 'hub_challenge' => '12345']))
        ->assertOk()->assertSee('12345');

    // The .env value no longer applies once the studio saved one.
    $this->get(route('webhooks.whatsapp.verify', ['hub_mode' => 'subscribe', 'hub_verify_token' => 'env-verify', 'hub_challenge' => '12345']))
        ->assertForbidden();
});

it('shows whatsapp as configured on the card and connected after a live test', function () {
    $this->actingAs($this->userWithRole('admin'))->get(route('admin.integrations'))->assertOk()->assertSee('WhatsApp Cloud API');

    // A configured credential without traffic now reads Needs check in the unified grid.
    $component = Livewire::actingAs($this->userWithRole('admin'))->test(Integrations::class);
    $component->assertSee('Needs check')->assertViewHas('providers', fn ($providers) => collect($providers)->firstWhere('key', 'whatsapp')['state'] === 'check');

    $gateway = (new FakeWhatsAppGateway)->configured(true);
    $this->app->instance(WhatsAppGateway::class, $gateway);

    $component->call('testWhatsApp')
        ->assertSee('Connected')
        ->assertSee('+92 300 1234567');
});

it('keeps the whatsapp screen for staff who may manage integrations', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.settings', ['tab' => 'whatsapp']))->assertForbidden();
    $this->actingAs(User::factory()->create())->get(route('admin.whatsapp.templates'))->assertForbidden();
});
