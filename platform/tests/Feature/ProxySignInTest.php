<?php

declare(strict_types=1);

use App\Http\Responses\AuthLoginResponse;
use Illuminate\Support\Facades\Hash;

it('renders a same-origin relative sign-in action even when the backend sees HTTP', function () {
    $this->get('http://internal-preview.test/admin/login')->assertOk()
        ->assertSee('action="/admin/login"', false)->assertSee('data-two-factor-url="/admin/two-factor-challenge"', false);
});

it('returns a relative intended destination instead of a proxy HTTP redirect after normal authentication', function () {
    $user = $this->userWithRole('owner', ['password' => Hash::make('Proxy-fixture-105')]);
    $this->withSession(['url.intended' => 'http://localhost/admin/projects?tab=costs'])
        ->postJson(route('login.store'), ['email' => $user->email, 'password' => 'Proxy-fixture-105'])
        ->assertOk()->assertJson(['two_factor' => false, 'redirect' => '/admin/projects?tab=costs'])->assertSessionMissing('url.intended');
    $this->assertAuthenticatedAs($user);
});

it('does not navigate to an external intended destination', function () {
    $user = $this->userWithRole('owner', ['password' => Hash::make('Proxy-fixture-105')]);
    $this->withSession(['url.intended' => 'https://external.invalid/steal'])
        ->postJson(route('login.store'), ['email' => $user->email, 'password' => 'Proxy-fixture-105'])
        ->assertOk()->assertJsonPath('redirect', '/admin');
});

it('still rejects wrong and inactive credentials through the same Fortify pipeline', function () {
    $user = $this->userWithRole('owner', ['password' => Hash::make('Proxy-fixture-105'), 'is_active' => false]);
    $this->postJson(route('login.store'), ['email' => $user->email, 'password' => 'Proxy-fixture-105'])->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->assertGuest();
});

it('still challenges confirmed two-factor accounts without authenticating or consuming the intended URL', function () {
    $user = $this->userWithRole('owner', ['password' => Hash::make('Proxy-fixture-105'), 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
    $this->withSession(['url.intended' => '/admin/projects'])->postJson(route('login.store'), ['email' => $user->email, 'password' => 'Proxy-fixture-105'])
        ->assertOk()->assertJson(['two_factor' => true])->assertSessionHas('url.intended', '/admin/projects');
    $this->assertGuest();
});

it('preserves the native final two-factor verification and recovery-code consumption', function () {
    $user = $this->userWithRole('owner', ['password' => Hash::make('Proxy-fixture-105'), 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => encrypt(json_encode(['fixture-only-recovery']))]);
    $this->withSession(['url.intended' => '/admin/projects'])->postJson(route('login.store'), ['email' => $user->email, 'password' => 'Proxy-fixture-105'])->assertJson(['two_factor' => true]);
    $this->postJson(route('two-factor.login.store'), ['recovery_code' => 'wrong-recovery'])->assertUnprocessable();
    $this->assertGuest();
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'fixture-only-recovery'])->assertRedirect('/admin/projects');
    $this->assertAuthenticatedAs($user);
    expect(decrypt($user->fresh()->two_factor_recovery_codes))->not->toContain('fixture-only-recovery');
});

it('keeps JavaScript-disabled native Fortify login redirects unchanged', function () {
    $user = $this->userWithRole('owner', ['password' => Hash::make('Proxy-fixture-105')]);
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Proxy-fixture-105'])->assertRedirect('/admin');
    $this->assertAuthenticatedAs($user);
});

it('rejects nonlocal malformed credentialed and protocol-relative redirect values', function (mixed $value) {
    expect(AuthLoginResponse::relativeDestination($value, 'internal.test', 'https://public.test'))->toBeNull();
})->with([null, '', '//external.invalid/', '/\\external.invalid/', "\n/admin", 'javascript:alert(1)', 'https://external.invalid/admin', 'https://user:pass@public.test/admin', 'https://public.test//external.invalid/']);

it('normalizes only known self origins and preserves local paths and queries', function (string $value, string $expected) {
    expect(AuthLoginResponse::relativeDestination($value, 'internal.test', 'https://public.test'))->toBe($expected);
})->with([
    ['/admin/projects?tab=costs', '/admin/projects?tab=costs'],
    ['http://internal.test/admin', '/admin'],
    ['https://public.test/admin?tab=1#review', '/admin?tab=1#review'],
]);
