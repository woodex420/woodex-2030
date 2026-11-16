<?php

use App\Support\SyntheticPreview;
use Illuminate\Support\Facades\Hash;

it('keeps synthetic guidance off unless explicitly enabled', function () {
    config(['preview.synthetic' => false, 'preview.login_email' => 'owner@woodex.test']);
    expect(SyntheticPreview::enabled())->toBeFalse();
    $this->get(route('login'))->assertOk()->assertDontSee('Synthetic preview — not production')->assertDontSee('owner@woodex.test');
});

it('shows the opt-in identity hint only on SQLite without providing a password or bypass', function () {
    config(['preview.synthetic' => true, 'preview.login_email' => 'owner@woodex.test']);
    $isSqlite = config('database.default') === 'sqlite';
    expect(SyntheticPreview::enabled())->toBe($isSqlite);
    $response = $this->get(route('login'))->assertOk();
    if ($isSqlite) {
        $response->assertSee('Synthetic preview — not production')
            ->assertSee('Your production staff credentials will not work here.')
            ->assertSee('owner@woodex.test')->assertSee('value="owner@woodex.test"', false);
    } else {
        // Hosted CI intentionally uses MySQL; its sign-in must not advertise a synthetic account.
        $response->assertDontSee('Synthetic preview — not production')->assertDontSee('owner@woodex.test');
    }
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('never shows synthetic guidance in production or staging even when enabled', function (string $environment) {
    config(['preview.synthetic' => true, 'preview.login_email' => 'owner@woodex.test']);
    app()->detectEnvironment(fn () => $environment);
    expect(SyntheticPreview::enabled())->toBeFalse();
    $this->get(route('login'))->assertOk()->assertDontSee('Synthetic preview — not production')->assertDontSee('owner@woodex.test');
})->with(['production', 'staging']);

it('never labels a non-sqlite runtime as the synthetic preview', function () {
    $connection = config('database.default');
    try {
        config(['preview.synthetic' => true, 'database.default' => 'mysql']);
        expect(SyntheticPreview::enabled())->toBeFalse();
    } finally {
        config(['database.default' => $connection]);
    }
    $sqlite = config('database.connections.sqlite');
    try {
        foreach ([['driver' => 'mysql'], ['url' => 'mysql://synthetic.invalid/never-connect'], ['read' => ['database' => 'ordinary.sqlite']], ['write' => ['database' => 'ordinary.sqlite']]] as $override) {
            config(['database.default' => 'sqlite', 'database.connections.sqlite' => array_replace($sqlite, $override)]);
            expect(SyntheticPreview::enabled())->toBeFalse()->and(SyntheticPreview::credentials())->toBeNull();
        }
    } finally {
        config(['database.default' => $connection, 'database.connections.sqlite' => $sqlite]);
    }
});

it('rejects an incorrect password without flashing the password', function () {
    config(['preview.synthetic' => true, 'preview.login_email' => 'owner@woodex.test']);
    $user = $this->userWithRole('owner', ['password' => Hash::make('correct-fixture-password')]);
    $this->from(route('login'))->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-fixture-password'])
        ->assertRedirect(route('login'))->assertSessionHasErrors('email')->assertSessionMissing('_old_input.password');
    $this->assertGuest();
});

it('preserves the entered email instead of replacing it with the hint', function () {
    config(['preview.synthetic' => true, 'preview.login_email' => 'owner@woodex.test']);
    $this->withSession(['_old_input' => ['email' => 'entered@woodex.test']])
        ->get(route('login'))->assertSee('value="entered@woodex.test"', false)->assertDontSee('value="owner@woodex.test"', false);
});

it('renders failed-login feedback as an accessible alert', function () {
    $this->withViewErrors(['email' => [__('auth.failed')]])->view('auth.login')
        ->assertSee('role="alert"', false)->assertSee(__('auth.failed'));
});

it('still requires an active staff account and authenticates the correct password normally', function () {
    config(['preview.synthetic' => true]);
    $user = $this->userWithRole('owner', ['password' => Hash::make('correct-fixture-password'), 'is_active' => true]);
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'correct-fixture-password'])->assertRedirect('/admin');
    $this->assertAuthenticatedAs($user);
});

it('does not let preview guidance reactivate an inactive staff account', function () {
    config(['preview.synthetic' => true]);
    $user = $this->userWithRole('owner', ['password' => Hash::make('correct-fixture-password'), 'is_active' => false]);
    $this->from(route('login'))->post(route('login.store'), ['email' => $user->email, 'password' => 'correct-fixture-password'])
        ->assertRedirect(route('login'))->assertSessionHasErrors('email');
    $this->assertGuest();
});
