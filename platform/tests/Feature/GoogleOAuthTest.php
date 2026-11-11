<?php

use App\Domain\System\Models\IntegrationLog;
use App\Integrations\Google\GoogleConnection;
use App\Integrations\Google\GoogleIdentity;
use App\Integrations\Google\GoogleOAuth;
use App\Livewire\Admin\Profile\Security;
use App\Livewire\Admin\System\Integrations;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    config(['services.google.client_id' => 'client-id-fixture', 'services.google.client_secret' => 'client-secret-fixture',
        'services.google.redirect_uri' => 'https://woodex.test/admin/google/callback', 'services.google.sign_in_enabled' => true]);
    fakeGoogle();
});

function fakeGoogle(array $overrides = []): void
{
    // Http::fake merges callbacks; reset the factory so overrides really exercise failure paths.
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(array_replace([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token-fixture', 'refresh_token' => 'refresh-token-fixture',
            'expires_in' => 3600, 'token_type' => 'Bearer', 'scope' => 'openid email profile']),
        'openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'google-subject-fixture', 'email' => 'google@woodex.test', 'email_verified' => true]),
        'oauth2.googleapis.com/revoke' => Http::response('', 200),
    ], $overrides));
}

function googleContext(string $purpose, ?User $user = null): array
{
    return ['state' => str_repeat('a', 64), 'verifier' => str_repeat('v', 86), 'issued_at' => now()->timestamp,
        'purpose' => $purpose, 'user_id' => $user?->id];
}

function googleCallbackUrl(string $state = ''): string
{
    return route('google.callback', ['code' => 'authorization-code-fixture', 'state' => $state ?: str_repeat('a', 64)]);
}

it('shows an honest setup modal without requesting provider credentials', function () {
    config(['services.google.client_id' => null, 'services.google.client_secret' => null]);
    $this->actingAs($this->userWithRole('owner'));
    Livewire::test(Integrations::class)->call('openGoogleSetup')->assertSee('Server setup required')
        ->assertSee('Complete server setup to connect')->assertDontSee('client-secret-fixture')
        ->assertSee('No Drive files, spreadsheets, email, calendar or meetings.');
    Http::assertNothingSent();
});

it('keeps Google sign-in hidden and refuses starts until configured and explicitly enabled', function () {
    config(['services.google.sign_in_enabled' => false]);
    $this->get(route('login'))->assertDontSee('Continue with Google');
    $this->get(route('google.login'))->assertRedirect(route('login'))->assertSessionMissing('google.oauth');
    config(['services.google.sign_in_enabled' => true, 'services.google.client_secret' => null]);
    $this->get(route('google.login'))->assertRedirect(route('login'))->assertSessionMissing('google.oauth');
    Http::assertNothingSent();
});

it('rejects unsafe callback configuration and only permits loopback HTTP outside production', function () {
    foreach (['http://evil.test/callback', 'https://user@evil.test/callback', 'https://evil.test/callback?token=x', 'https://evil.test/callback#x'] as $uri) {
        config(['services.google.redirect_uri' => $uri]);
        expect(GoogleOAuth::configured())->toBeFalse();
    }
    config(['services.google.redirect_uri' => 'http://127.0.0.1:8080/admin/google/callback']);
    expect(GoogleOAuth::configured())->toBeTrue();
    app()->detectEnvironment(fn () => 'production');
    expect(GoogleOAuth::configured())->toBeFalse();
});

it('requires an active owner and current password before company authorization', function () {
    $this->post(route('google.connect'))->assertRedirect(route('login'));
    $this->actingAs($this->userWithRole('admin', ['password' => 'test-password']))
        ->post(route('google.connect'), ['password' => 'test-password'])->assertForbidden();
    $this->actingAs($this->userWithRole('owner', ['password' => 'test-password']))
        ->post(route('google.connect'), ['password' => 'wrong'])->assertRedirect(route('admin.integrations'))
        ->assertSessionHasErrors('password')->assertSessionMissing('google.oauth')->assertSessionMissing('_old_input.password');
    Http::assertNothingSent();
});

it('redirects to the fixed Google consent endpoint with state PKCE and minimal scopes', function () {
    $this->actingAs($this->userWithRole('owner', ['password' => 'test-password']));
    $response = $this->post(route('google.connect'), ['password' => 'test-password'])->assertRedirect();
    $uri = parse_url($response->headers->get('Location'));
    parse_str($uri['query'], $query);
    expect($uri['host'])->toBe('accounts.google.com')->and($uri['path'])->toBe('/o/oauth2/v2/auth')
        ->and($query['scope'])->toBe('openid email profile')->and($query['access_type'])->toBe('offline')
        ->and($query['code_challenge_method'])->toBe('S256')->and($query)->not->toHaveKey('client_secret');
    $verifier = session('google.oauth.verifier');
    expect($query['code_challenge'])->toBe(rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='))
        ->and($query['state'])->toBe(session('google.oauth.state'));
    Http::assertNothingSent();
});

it('consumes denied invalid expired and replayed states without exchanging credentials', function (string $failure) {
    $context = googleContext('login');
    if ($failure === 'expired') {
        $context['issued_at'] = now()->subMinutes(11)->timestamp;
    }
    $url = $failure === 'denied' ? route('google.callback', ['state' => $context['state'], 'error' => 'access_denied'])
        : googleCallbackUrl($failure === 'mismatch' ? 'wrong' : '');
    $this->withSession(['google' => ['oauth' => $context]])->get($url)->assertRedirect(route('login'))->assertSessionMissing('google.oauth');
    $this->get(googleCallbackUrl())->assertRedirect(route('login'));
    Http::assertNothingSent();
})->with(['expired', 'mismatch', 'denied']);

it('stores business tokens encrypted and hidden and keeps audits metadata-only', function () {
    $owner = $this->userWithRole('owner');
    $this->actingAs($owner)->withSession(['google' => ['oauth' => googleContext('business', $owner)]])
        ->get(googleCallbackUrl())->assertRedirect(route('admin.integrations'))->assertSessionMissing('google.oauth');
    $connection = GoogleConnection::query()->sole();
    $raw = DB::table('google_connections')->first();
    expect($raw->access_token)->not->toContain('access-token-fixture')->and($raw->refresh_token)->not->toContain('refresh-token-fixture')
        ->and($connection->access_token)->toBe('access-token-fixture')->and($connection->toArray())->not->toHaveKeys(['access_token', 'refresh_token'])
        ->and($connection->status)->toBe('active')->and(GoogleIdentity::query()->count())->toBe(0);
    $this->get(route('admin.integrations'))->assertOk()->assertDontSee('access-token-fixture')->assertDontSee('refresh-token-fixture');
    expect(IntegrationLog::query()->where('integration', 'google')->get()->toJson())->not->toContain('access-token-fixture', 'refresh-token-fixture', 'client-secret-fixture');
    Http::assertSent(fn (ClientRequest $r) => $r->url() === 'https://oauth2.googleapis.com/token' && $r['code_verifier'] === str_repeat('v', 86));
    $this->get(googleCallbackUrl())->assertRedirect(route('login'));
    expect(GoogleConnection::query()->count())->toBe(1);
});

it('rechecks the actor and owner role at the business callback', function (string $failure) {
    $owner = $this->userWithRole('owner');
    $context = googleContext('business', $owner);
    if ($failure === 'demoted') {
        $owner->removeRole('owner')->assignRole('sales');
        $this->actingAs($owner);
    } elseif ($failure === 'different') {
        $this->actingAs($this->userWithRole('owner'));
    }
    $this->withSession(['google' => ['oauth' => $context]])->get(googleCallbackUrl())->assertRedirect(route('admin.integrations'));
    expect(GoogleConnection::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with(['demoted', 'different', 'signed-out']);

it('does not trust unverified identities or token errors and redacts provider response bodies', function (string $failure) {
    if ($failure === 'unverified') {
        fakeGoogle(['openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 's', 'email' => 'google@woodex.test', 'email_verified' => false])]);
    } else {
        fakeGoogle(['oauth2.googleapis.com/token' => Http::response(['error' => 'client-secret-fixture access-token-fixture'], 400)]);
    }
    $this->withSession(['google' => ['oauth' => googleContext('login')]])->get(googleCallbackUrl())->assertRedirect(route('login'));
    expect(json_encode(session()->all()))->not->toContain('client-secret-fixture', 'access-token-fixture');
    expect(session('google_status'))->toBe('Google connection could not be completed. Check setup and permissions, then try again.');
    $this->assertGuest();
})->with(['unverified', 'token-error']);

it('never creates users or automatically links by matching email', function () {
    $this->userWithRole('sales', ['email' => 'google@woodex.test']);
    $count = User::query()->count();
    $this->withSession(['google' => ['oauth' => googleContext('login')]])->get(googleCallbackUrl())->assertRedirect(route('login'));
    $this->assertGuest();
    expect(User::query()->count())->toBe($count)->and(GoogleIdentity::query()->count())->toBe(0);
});

it('links a staff identity only after password confirmation and never saves staff tokens', function () {
    $user = $this->userWithRole('sales', ['password' => 'test-password']);
    $this->actingAs($user)->post(route('google.link'), ['password' => 'wrong'])->assertSessionHasErrors('password');
    $this->post(route('google.link'), ['password' => 'test-password'])->assertRedirect();
    $state = session('google.oauth.state');
    $this->get(googleCallbackUrl($state))->assertRedirect(route('admin.profile.security'));
    expect(GoogleIdentity::query()->sole()->user_id)->toBe($user->id)->and(GoogleConnection::query()->count())->toBe(0);
    Livewire::test(Security::class)->assertSee('google@woodex.test')->assertDontSee('access-token-fixture');
});

it('does not reassign an existing Google identity to another staff account', function () {
    $first = $this->userWithRole('sales');
    GoogleIdentity::query()->create(['user_id' => $first->id, 'google_subject' => 'google-subject-fixture', 'email' => 'google@woodex.test']);
    $second = $this->userWithRole('sales');
    $this->actingAs($second)->withSession(['google' => ['oauth' => googleContext('link', $second)]])->get(googleCallbackUrl());
    expect(GoogleIdentity::query()->sole()->user_id)->toBe($first->id);
});

it('signs in only the active prelinked staff user without changing roles', function () {
    $user = $this->userWithRole('sales');
    GoogleIdentity::query()->create(['user_id' => $user->id, 'google_subject' => 'google-subject-fixture', 'email' => 'google@woodex.test']);
    $this->withSession(['google' => ['oauth' => googleContext('login')]])->get(googleCallbackUrl())->assertRedirect(route('admin.dashboard'));
    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->getRoleNames()->all())->toBe(['sales']);
});

it('denies Google sign-in for disabled or deleted staff', function (bool $deleted) {
    $user = $this->userWithRole('sales');
    GoogleIdentity::query()->create(['user_id' => $user->id, 'google_subject' => 'google-subject-fixture', 'email' => 'google@woodex.test']);
    $deleted ? $user->delete() : $user->update(['is_active' => false]);
    $this->withSession(['google' => ['oauth' => googleContext('login')]])->get(googleCallbackUrl())->assertRedirect(route('login'));
    $this->assertGuest();
})->with([false, true]);

it('preserves confirmed owner two-factor challenges after Google authentication', function () {
    $user = $this->userWithRole('owner');
    $secret = 'JBSWY3DPEHPK3PXP';
    $user->forceFill(['two_factor_secret' => encrypt($secret), 'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-fixture']))])->save();
    GoogleIdentity::query()->create(['user_id' => $user->id, 'google_subject' => 'google-subject-fixture', 'email' => 'google@woodex.test']);
    $this->withSession(['google' => ['oauth' => googleContext('login')]])->get(googleCallbackUrl())
        ->assertRedirect(route('two-factor.login'))->assertSessionHas('login.id', $user->id);
    $this->assertGuest();
    $this->post(route('two-factor.login.store'), ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertRedirect();
    $this->assertAuthenticatedAs($user);
});

it('requires password confirmation to unlink staff identity and keeps the staff account', function () {
    $user = $this->userWithRole('sales', ['password' => 'test-password']);
    GoogleIdentity::query()->create(['user_id' => $user->id, 'google_subject' => 'google-subject-fixture', 'email' => 'google@woodex.test']);
    $this->actingAs($user)->post(route('google.unlink'), ['password' => 'wrong'])->assertSessionHasErrors('password');
    expect(GoogleIdentity::query()->count())->toBe(1);
    $this->post(route('google.unlink'), ['password' => 'test-password'])->assertRedirect(route('admin.profile.security'));
    expect(GoogleIdentity::query()->count())->toBe(0)->and($user->fresh()->is_active)->toBeTrue();
});

it('refreshes expiring company credentials and confirms the same Google identity', function () {
    $owner = $this->userWithRole('owner');
    $this->actingAs($owner)->withSession(['google' => ['oauth' => googleContext('business', $owner)]])->get(googleCallbackUrl());
    GoogleConnection::query()->sole()->update(['expires_at' => now()->subMinute()]);
    $this->post(route('google.check'))->assertRedirect(route('admin.integrations'));
    Http::assertSent(fn (ClientRequest $r) => $r->url() === 'https://oauth2.googleapis.com/token' && $r['grant_type'] === 'refresh_token');
    expect(GoogleConnection::query()->sole()->status)->toBe('active');
});

it('only removes company tokens after confirmed provider revocation', function (bool $success) {
    $owner = $this->userWithRole('owner', ['password' => 'test-password']);
    $this->actingAs($owner)->withSession(['google' => ['oauth' => googleContext('business', $owner)]])->get(googleCallbackUrl());
    fakeGoogle(['oauth2.googleapis.com/revoke' => Http::response('', $success ? 200 : 500)]);
    $this->post(route('google.disconnect'), ['password' => 'test-password'])->assertRedirect(route('admin.integrations'));
    expect(GoogleConnection::query()->count())->toBe($success ? 0 : 1);
    if (! $success) {
        expect(GoogleConnection::query()->sole()->status)->toBe('revocation_pending');
    }
    Http::assertSent(fn (ClientRequest $r) => $r->url() === 'https://oauth2.googleapis.com/revoke' && $r['token'] === 'refresh-token-fixture');
})->with([false, true]);

it('rejects additional Workspace permissions or missing offline grants on company connections', function (string $failure) {
    $tokens = ['access_token' => 'access-token-fixture', 'token_type' => 'Bearer', 'scope' => 'openid email profile'];
    if ($failure === 'extra-scopes') {
        $tokens['refresh_token'] = 'refresh-token-fixture';
        $tokens['scope'] .= ' https://www.googleapis.com/auth/drive';
    }
    fakeGoogle(['oauth2.googleapis.com/token' => Http::response($tokens)]);
    $owner = $this->userWithRole('owner');
    $this->actingAs($owner)->withSession(['google' => ['oauth' => googleContext('business', $owner)]])->get(googleCallbackUrl());
    expect(GoogleConnection::query()->count())->toBe(0)->and(session('google_status'))->toContain('could not be completed');
})->with(['extra-scopes', 'missing-refresh']);

it('requires an owner for live checks and revocation and never bypasses password confirmation', function () {
    $owner = $this->userWithRole('owner');
    $this->actingAs($owner)->withSession(['google' => ['oauth' => googleContext('business', $owner)]])->get(googleCallbackUrl());
    $admin = $this->userWithRole('admin', ['password' => 'test-password']);
    $this->actingAs($admin)->post(route('google.check'))->assertForbidden();
    $this->post(route('google.disconnect'), ['password' => 'test-password'])->assertForbidden();
    $this->actingAs($owner)->post(route('google.disconnect'), ['password' => 'wrong'])->assertSessionHasErrors('password');
    expect(GoogleConnection::query()->sole()->status)->toBe('active');
});

it('marks failed refreshes as reconnect required without exposing provider errors', function () {
    $owner = $this->userWithRole('owner');
    $this->actingAs($owner)->withSession(['google' => ['oauth' => googleContext('business', $owner)]])->get(googleCallbackUrl());
    GoogleConnection::query()->sole()->update(['expires_at' => now()->subMinute()]);
    fakeGoogle(['oauth2.googleapis.com/token' => Http::response(['error' => 'refresh-token-fixture'], 400)]);
    $this->post(route('google.check'))->assertRedirect(route('admin.integrations'));
    expect(GoogleConnection::query()->sole()->status)->toBe('reconnect_required')
        ->and(json_encode(session()->all()))->not->toContain('refresh-token-fixture');
});

it('does not replace a signed-in session through a guest Google callback', function () {
    $user = $this->userWithRole('sales');
    $this->actingAs($user)->withSession(['google' => ['oauth' => googleContext('login')]])->get(googleCallbackUrl());
    $this->assertAuthenticatedAs($user);
    Http::assertNothingSent();
});

it('rate limits Google sign-in starts', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->get(route('google.login'))->assertRedirect();
    }
    $this->get(route('google.login'))->assertTooManyRequests();
    Http::assertNothingSent();
});

it('does not use Google linking to skip required staff two-factor enrolment', function () {
    config(['woodex.security.enforce_2fa' => true, 'woodex.security.require_2fa_roles' => ['admin']]);
    $user = $this->userWithRole('admin', ['password' => 'test-password']);
    $this->actingAs($user)->post(route('google.link'), ['password' => 'test-password'])->assertForbidden();
    Http::assertNothingSent();
});

it('does not cache OAuth responses or forward callback query strings as referrers', function () {
    $this->get(route('google.login'))->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Cache-Control', 'no-store, private');
    $this->withSession(['google' => ['oauth' => googleContext('login')]])->get(googleCallbackUrl())
        ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Cache-Control', 'no-store, private');
});
