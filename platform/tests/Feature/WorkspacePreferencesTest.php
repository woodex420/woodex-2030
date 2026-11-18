<?php

use App\Livewire\Admin\System\FeatureStatus;
use App\Livewire\Admin\System\WorkspacePreferences as Screen;
use App\Models\User;
use App\Support\ReleaseNotes;
use App\Support\WorkspacePreferences;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
| Workspace preferences (master list T-056): each person's own appearance, single-key shortcuts and start screen,
| and the welcome that a first sign-in is led to. Each choice changes something that can be seen, and each is
| asserted where it is seen: in the page, or in where sign-in goes.
*/

it('is open to every role, and each person changes only their own', function (string $role) {
    $person = $this->userWithRole($role);
    $other = $this->userWithRole('owner');

    $this->actingAs($person)->get(route('admin.system.preferences'))->assertOk()->assertSee('Workspace preferences');

    Livewire::actingAs($person)->test(Screen::class)
        ->set('appearance', 'dark')->set('shortcuts', false)->set('start', 'feature-status')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('wx-preferences-saved', appearance: 'dark', shortcuts: false)
        ->assertDispatched('toast', type: 'success');

    expect($person->fresh()->preferences)->toMatchArray(['appearance' => 'dark', 'shortcuts' => false, 'start' => 'feature-status'])
        ->and($other->fresh()->preferences)->toBeNull();
})->with(['owner', 'sales_manager', 'sales', 'designer_pm', 'accountant', 'editor', 'support']);

it('writes the appearance and the shortcuts into the page, for the script that paints it', function () {
    $person = $this->userWithRole('sales');

    $this->actingAs($person)->get(route('admin.dashboard'))->assertOk()
        ->assertSee('data-theme-default="system"', false)->assertSee('data-shortcuts="on"', false);

    WorkspacePreferences::save($person, ['appearance' => 'dark', 'shortcuts' => false, 'start' => '']);

    $this->actingAs($person->fresh())->get(route('admin.dashboard'))->assertOk()
        ->assertSee('data-theme-default="dark"', false)->assertSee('data-shortcuts="off"', false);
});

it('refuses a start screen the person cannot open, and an appearance that is not one', function () {
    $sales = $this->userWithRole('sales');

    expect(WorkspacePreferences::startScreens($sales))->toHaveKey('leads')->not->toHaveKey('settings');

    Livewire::actingAs($sales)->test(Screen::class)->set('start', 'settings')->call('save')
        ->assertHasErrors('start')->assertDispatched('toast', type: 'error');
    Livewire::actingAs($sales)->test(Screen::class)->set('appearance', 'purple')->call('save')
        ->assertHasErrors('appearance');

    expect($sales->fresh()->preferences)->toBeNull();
});

it('opens the chosen screen after sign-in, and the dashboard when that screen is no longer theirs', function () {
    $person = User::factory()->create(['password' => 'a-long-password-for-this-test', 'last_login_at' => now()->subDay()]);
    $person->assignRole('sales_manager');
    WorkspacePreferences::save($person, ['appearance' => 'system', 'shortcuts' => true, 'start' => 'leads']);

    $this->post(route('login'), ['email' => $person->email, 'password' => 'a-long-password-for-this-test'])
        ->assertRedirect(route('admin.leads.index'));
    $this->post(route('logout'));

    // The enhanced sign-in asks as JSON and is told a path of this site.
    $this->postJson(route('login'), ['email' => $person->email, 'password' => 'a-long-password-for-this-test'])
        ->assertOk()->assertJson(['two_factor' => false, 'redirect' => route('admin.leads.index', [], false)]);
    $this->post(route('logout'));

    // A link followed before signing in still wins.
    $this->get(route('admin.contacts.index'))->assertRedirect();
    $this->post(route('login'), ['email' => $person->email, 'password' => 'a-long-password-for-this-test'])
        ->assertRedirect(route('admin.contacts.index'));
    $this->post(route('logout'));

    // The role changed: the chosen screen is no longer in this person's menu.
    $person->syncRoles(['accountant']);
    expect(WorkspacePreferences::start($person->fresh()))->toBe('admin.dashboard');
    $this->post(route('login'), ['email' => $person->email, 'password' => 'a-long-password-for-this-test'])
        ->assertRedirect(route('admin.dashboard'));
});

it('leads a first sign-in to the welcome, once', function () {
    $member = User::factory()->create(['password' => 'a-long-password-for-this-test', 'last_login_at' => null]);
    $member->assignRole('sales');

    // Whatever link was followed before: the welcome comes first.
    $this->withSession(['url.intended' => '/admin/contacts'])
        ->post(route('login'), ['email' => $member->email, 'password' => 'a-long-password-for-this-test'])
        ->assertRedirect(route('admin.system.preferences', ['welcome' => 1]));

    $this->get(route('admin.system.preferences', ['welcome' => 1]))->assertOk()
        ->assertSee('data-welcome', false)
        ->assertSee('Welcome, '.$member->name)
        ->assertSee('Protect your sign-in')->assertSee('Make the workspace yours')->assertSee('See what you can open')
        // What the member can open is listed; what they cannot is not.
        ->assertSee('Leads')->assertDontSee('Access &amp; team', false);
    expect(session(WorkspacePreferences::FIRST_SIGN_IN))->toBeNull();

    $this->post(route('logout'));
    $this->post(route('login'), ['email' => $member->email, 'password' => 'a-long-password-for-this-test'])
        ->assertRedirect(route('admin.dashboard'));
    $this->get(route('admin.system.preferences'))->assertOk()->assertDontSee('data-welcome', false);
});

it('leads a member whose role needs two-factor through the security page first, and on from there', function () {
    config(['woodex.security.enforce_2fa' => true, 'woodex.security.require_2fa_roles' => ['accountant']]);
    $member = User::factory()->create(['password' => 'a-long-password-for-this-test', 'last_login_at' => null]);
    $member->assignRole('accountant');

    $this->post(route('login'), ['email' => $member->email, 'password' => 'a-long-password-for-this-test'])
        ->assertRedirect(route('admin.system.preferences', ['welcome' => 1]));
    // No screen but the security page until two-factor is on; the welcome is still owed.
    $this->get(route('admin.system.preferences', ['welcome' => 1]))->assertRedirect(route('admin.profile.security'));
    $this->get(route('admin.profile.security'))->assertOk()->assertDontSee('data-first-steps', false);
    expect(session(WorkspacePreferences::FIRST_SIGN_IN))->toBeTrue();

    $member->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    // The test keeps one application between requests, and with it the person as read at sign-in.
    $this->actingAs($member->fresh());

    $this->get(route('admin.profile.security'))->assertOk()
        ->assertSee('data-first-steps', false)->assertSee(route('admin.system.preferences', ['welcome' => 1]), false);
    $this->get(route('admin.system.preferences', ['welcome' => 1]))->assertOk()
        ->assertSee('data-welcome', false)->assertSee('Two-factor sign-in is on');
});

it('shows what is new on Feature status, newest first, in words for the person at the screen', function () {
    $releases = ReleaseNotes::all();
    $dates = array_column($releases, 'date');
    $sorted = $dates;
    rsort($sorted);

    expect($releases)->not->toBeEmpty()->and($dates)->toBe($sorted);
    foreach ($releases as $release) {
        expect(Carbon::createFromFormat('Y-m-d', $release['date'])->format('Y-m-d'))->toBe($release['date'])
            ->and($release['title'])->not->toBe('')
            ->and($release['notes'])->not->toBeEmpty();
        foreach ($release['notes'] as $note) {
            // A list line or a commit is not a release note.
            expect($note)->not->toMatch('/\bT-\d{3}\b|\b[0-9a-f]{7,40}\b|pull request/i');
        }
    }

    Livewire::actingAs($this->userWithRole('sales'))->test(FeatureStatus::class)
        ->assertSee('What is new')->assertSeeHtml('data-release="'.$releases[0]['date'].'"')->assertSee($releases[0]['notes'][0]);
});
