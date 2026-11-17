<?php

use App\Livewire\Admin\Dashboard;
use App\Models\User;

it('redirects guests to the admin login', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    $this->get(route('login'))->assertOk()->assertSee('Sign in');
});

it('signs in an active staff member and blocks deactivated accounts', function () {
    $user = $this->userWithRole('sales', ['email' => 'sales@woodex.test', 'password' => 'secret-pass-1']);

    $this->post(route('login.store'), ['email' => 'sales@woodex.test', 'password' => 'secret-pass-1'])
        ->assertRedirect(config('fortify.home'));
    $this->assertAuthenticatedAs($user);

    auth()->logout();
    $user->update(['is_active' => false]);

    $this->post(route('login.store'), ['email' => 'sales@woodex.test', 'password' => 'secret-pass-1']);
    $this->assertGuest();
});

it('sends a failed sign-in back to its form even without a referer or a session', function () {
    // No Referer header and no session here, like a proxy that drops Referer and a browser that drops the
    // cookies: Laravel's fallback would be "/", the public website when the admin lives under /admin (D-51).
    $this->post(route('login.store'), ['email' => 'admin@woodex.com.pk', 'password' => 'not-the-password'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->post(route('login.store'), ['email' => '', 'password' => ''])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email', 'password']);

    $this->post(route('password.email'), ['email' => 'not-an-email'])
        ->assertRedirect(route('password.request'))
        ->assertSessionHasErrors('email');

    $this->followingRedirects()
        ->post(route('login.store'), ['email' => 'admin@woodex.com.pk', 'password' => 'not-the-password'])
        ->assertOk()
        ->assertSee('These credentials do not match our records.');
});

it('sends signed-in staff to the admin dashboard, never to the public site', function () {
    // The owner's preview test on 2026-10-15: a double click on "Sign in" sent a second POST that arrived signed in,
    // and Laravel's default "already signed in" target is the route named "home" (the website) (D-51).
    $owner = $this->userWithRole('owner', ['email' => 'owner@woodex.test', 'password' => 'secret-pass-1']);

    $this->actingAs($owner)->get(route('login'))->assertRedirect(route('admin.dashboard'));
    $this->actingAs($owner)
        ->post(route('login.store'), ['email' => 'owner@woodex.test', 'password' => 'secret-pass-1'])
        ->assertRedirect(route('admin.dashboard'));
    $this->actingAs($owner)->get(route('password.request'))->assertRedirect(route('admin.dashboard'));
});

it('renders the owner dashboard with the go-live checklist', function () {
    $owner = $this->userWithRole('owner');

    $this->actingAs($owner)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSeeLivewire(Dashboard::class)
        ->assertSee('Go-live checklist')
        ->assertSee('Pipeline');
});

it('applies role permissions to admin screens', function () {
    $editor = $this->userWithRole('editor');
    $sales = $this->userWithRole('sales');
    $admin = $this->userWithRole('admin');

    $this->actingAs($editor)->get(route('admin.leads.index'))->assertForbidden();
    $this->actingAs($sales)->get(route('admin.leads.index'))->assertOk();
    $this->actingAs($sales)->get(route('admin.system.health'))->assertForbidden();
    $this->actingAs($admin)->get(route('admin.system.health'))->assertOk()->assertSee('Cron heartbeat');
});

it('forces two-factor setup for required staff roles when enforcement is on', function (string $role) {
    config(['woodex.security.enforce_2fa' => true]);
    $staff = $this->userWithRole($role);

    $this->actingAs($staff)->get(route('admin.dashboard'))->assertRedirect(route('admin.profile.security'));
    $this->actingAs($staff)->get(route('admin.profile.security'))->assertOk()->assertSee('Two-factor authentication');
})->with(['admin', 'accountant']);

it('sends noindex headers on admin pages', function () {
    $this->actingAs(tap(User::factory()->create())->assignRole('sales'))
        ->get(route('admin.leads.index'))
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});
