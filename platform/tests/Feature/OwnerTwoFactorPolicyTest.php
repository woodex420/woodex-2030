<?php

use App\Domain\System\Models\TelegramLink;
use App\Livewire\Admin\Profile\Security;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    // Include the old cached role list to ensure an already deployed site is unblocked too.
    config([
        'woodex.security.enforce_2fa' => true,
        'woodex.security.require_2fa_roles' => ['owner', 'admin', 'accountant'],
    ]);
});

it('lets owners use admin pages before optional setup even with a pending QR', function (bool $pending) {
    $owner = $this->userWithRole('owner');
    if ($pending) {
        app(EnableTwoFactorAuthentication::class)($owner);
    }
    // An owner with another required role is still an owner.
    $owner->assignRole('admin');
    $this->actingAs($owner);

    foreach (['admin.dashboard', 'admin.leads.index', 'admin.integrations', 'admin.profile.security'] as $route) {
        $this->get(route($route))->assertOk()->assertSessionMissing('warning');
    }
    $this->get(route('admin.profile.security'))->assertSee('2FA is optional for your account.');
    expect($owner->fresh()->two_factor_confirmed_at)->toBeNull();
})->with([false, true]);

it('lets an owner link Telegram without enabling or automatically requiring 2FA', function () {
    config(['services.telegram.bot_token' => 'test-token', 'services.telegram.webhook_secret' => 'test-secret']);
    $owner = $this->userWithRole('owner');
    $this->actingAs($owner);

    Livewire::test(Security::class)->call('createTelegramCode')->assertHasNoErrors();
    $link = TelegramLink::query()->where('user_id', $owner->id)->sole();
    $link->update(['chat_id' => 777, 'is_active' => true, 'linked_at' => now()]);

    $this->get(route('admin.integrations'))->assertOk();
    expect($owner->fresh()->hasTwoFactorEnabled())->toBeFalse()
        ->and($owner->fresh()->requiresTwoFactorAuthentication())->toBeFalse();
});

it('requires the owner password before cancelling pending setup or disabling confirmed 2FA', function (bool $confirmed) {
    $owner = $this->userWithRole('owner', ['password' => 'test-password']);
    app(EnableTwoFactorAuthentication::class)($owner);
    if ($confirmed) {
        $owner->forceFill(['two_factor_confirmed_at' => now()])->save();
    }
    $this->actingAs($owner);

    $screen = Livewire::test(Security::class)->set('password', 'wrong')->call('disableTwoFactor')->assertHasErrors('password');
    expect($owner->fresh()->two_factor_secret)->not->toBeNull();
    $screen->set('password', 'test-password')->call('disableTwoFactor')->assertHasNoErrors()
        ->assertSee('2FA is optional for your account.');
    expect($owner->fresh()->two_factor_secret)->toBeNull();
})->with([false, true]);

it('does not let required staff turn off their confirmed 2FA', function (string $role) {
    $staff = $this->userWithRole($role, ['password' => 'test-password']);
    app(EnableTwoFactorAuthentication::class)($staff);
    $staff->forceFill(['two_factor_confirmed_at' => now()])->save();
    $this->actingAs($staff);

    Livewire::test(Security::class)->set('password', 'test-password')->call('disableTwoFactor')->assertHasErrors('password');
    expect($staff->fresh()->hasTwoFactorEnabled())->toBeTrue();
})->with(['admin', 'accountant']);

it('still challenges owners at sign-in after they voluntarily enable and confirm 2FA', function () {
    $owner = $this->userWithRole('owner', ['email' => 'owner-policy@woodex.test', 'password' => 'test-password']);
    $this->actingAs($owner);
    $screen = Livewire::test(Security::class)->set('password', 'test-password')->call('enableTwoFactor');
    $secret = decrypt($owner->fresh()->two_factor_secret);
    $screen->set('code', (new Google2FA)->getCurrentOtp($secret))->call('confirmTwoFactor')->assertHasNoErrors();
    auth()->logout();

    $this->post(route('login.store'), ['email' => $owner->email, 'password' => 'test-password'])
        ->assertRedirect(route('two-factor.login'));
    $this->assertGuest();
});

it('lets an owner with unconfirmed setup sign in without an authenticator challenge', function () {
    $owner = $this->userWithRole('owner', ['email' => 'owner-pending@woodex.test', 'password' => 'test-password']);
    app(EnableTwoFactorAuthentication::class)($owner);

    $this->post(route('login.store'), ['email' => $owner->email, 'password' => 'test-password'])
        ->assertRedirect(config('fortify.home'));
    $this->assertAuthenticatedAs($owner);
    $this->get(route('admin.dashboard'))->assertOk();
});
