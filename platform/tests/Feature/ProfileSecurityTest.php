<?php

use App\Domain\System\Models\IntegrationLog;
use App\Domain\System\Models\TelegramLink;
use App\Integrations\Telegram\Clients\TelegramBotApi;
use App\Integrations\Telegram\TelegramMessage;
use App\Livewire\Admin\Profile\Security;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

it('shows a manual authenticator key and confirms a valid six digit code', function () {
    $user = $this->userWithRole('owner', ['password' => 'test-password']);
    $this->actingAs($user);

    $screen = Livewire::test(Security::class)
        ->set('password', 'test-password')->call('enableTwoFactor')
        ->assertSee('Enter a setup key');

    $secret = decrypt($user->fresh()->two_factor_secret);
    $screen->assertSee($secret)
        ->set('code', '12345')->call('confirmTwoFactor')->assertHasErrors('code');

    $screen->set('code', (new Google2FA)->getCurrentOtp($secret))->call('confirmTwoFactor')->assertHasNoErrors()
        ->assertSee('Your account is protected.')->assertDontSee($secret);
    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('does not issue a misleading link code when Telegram configuration is missing', function () {
    config(['services.telegram.bot_token' => null, 'services.telegram.webhook_secret' => null]);
    $user = $this->userWithRole('owner');
    $this->actingAs($user);

    // Slice L: the generic line is gone — the health ladder names the missing key itself.
    Livewire::test(Security::class)->assertSee('TELEGRAM_BOT_TOKEN is not set on the server.')
        ->call('createTelegramCode')->assertDispatched('toast', type: 'warning');
    expect(TelegramLink::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('refreshes linking state after actions and polling and invalidates codes when unlinked', function () {
    config(['services.telegram.bot_token' => 'test-token', 'services.telegram.webhook_secret' => 'test-secret', 'services.telegram.bot_username' => 'WoodexInteriorBot']);
    $user = $this->userWithRole('owner');
    $this->actingAs($user);
    $user->load('telegramLink'); // Simulate an already loaded, stale auth-user relation.

    $screen = Livewire::test(Security::class)->call('createTelegramCode');
    $link = TelegramLink::query()->where('user_id', $user->id)->sole();
    $screen->assertSee('https://t.me/WoodexInteriorBot?start='.$link->link_code, false);
    $link->update(['chat_id' => 777, 'is_active' => true, 'linked_at' => now()]);
    $screen->call('$refresh')->assertSee('Linked')->call('unlinkTelegram')->assertSee('Link Telegram');
    expect($link->fresh()->chat_id)->toBeNull()->and($link->fresh()->link_code)->toBeNull();
});

it('never stores the token from a Telegram transport exception', function () {
    $token = '123:TEST-SECRET';
    Http::fake(fn () => throw new ConnectionException('Failed https://api.telegram.org/bot'.$token.'/sendMessage'));

    expect((new TelegramBotApi($token))->send(777, new TelegramMessage('Test')))->toBeFalse();
    $log = IntegrationLog::query()->where('integration', 'telegram')->sole();
    expect($log->error)->toContain('Check server connectivity')->not->toContain($token);
});
