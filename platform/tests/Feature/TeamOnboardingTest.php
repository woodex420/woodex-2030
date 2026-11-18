<?php

use App\Domain\System\Notifications\StaffInvitation;
use App\Livewire\Admin\System\TeamOnboarding;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
| Team onboarding (master list T-056): for every active member, how far they are on the way in. The screen reads
| what the other screens write: the invitation, the link to set a password, the first sign-in, two-factor.
*/

function memberOnTheWay(string $name, string $role, array $with = []): User
{
    $member = User::factory()->create(['name' => $name, 'last_login_at' => null, ...$with]);
    $member->assignRole($role);

    return $member;
}

it('is for those who manage the team', function () {
    $this->actingAs($this->userWithRole('owner'))->get(route('admin.system.onboarding'))->assertOk()->assertSee('Team onboarding');
    $this->actingAs($this->userWithRole('sales'))->get(route('admin.system.onboarding'))->assertForbidden();
    $this->actingAs($this->userWithRole('accountant'))->get(route('admin.system.onboarding'))->assertForbidden();
});

it('tells for each member which steps are done', function () {
    config(['woodex.security.enforce_2fa' => true, 'woodex.security.require_2fa_roles' => ['accountant']]);
    $owner = $this->userWithRole('owner');
    $owner->forceFill(['last_login_at' => now()])->save();

    $waiting = memberOnTheWay('Asif Waiting', 'sales');
    DB::table('password_reset_tokens')->insert(['email' => $waiting->email, 'token' => 'x', 'created_at' => now()->subMinutes(5)]);
    $late = memberOnTheWay('Bina Late', 'sales');
    DB::table('password_reset_tokens')->insert(['email' => $late->email, 'token' => 'x', 'created_at' => now()->subDays(2)]);
    $unprotected = memberOnTheWay('Chand Accounts', 'accountant', ['last_login_at' => now()->subHour()]);
    $through = memberOnTheWay('Dua Through', 'sales', ['last_login_at' => now()->subHour()]);
    $gone = memberOnTheWay('Erum Gone', 'sales', ['is_active' => false]);

    $members = Livewire::actingAs($owner)->test(TeamOnboarding::class)->viewData('members')->keyBy('email');

    expect($members[$waiting->email])->toMatchArray(['link' => 'waiting', 'two_factor' => 'optional', 'through' => false])
        ->and($members[$late->email])->toMatchArray(['link' => 'expired', 'through' => false])
        ->and($members[$unprotected->email])->toMatchArray(['link' => 'used', 'two_factor' => 'missing', 'through' => false])
        ->and($members[$through->email])->toMatchArray(['link' => 'used', 'two_factor' => 'optional', 'through' => true])
        ->and($members[$owner->email])->toMatchArray(['through' => true])
        ->and($members->has($gone->email))->toBeFalse()
        // Who still needs something comes first.
        ->and($members->take(3)->pluck('through')->all())->toBe([false, false, false]);

    Livewire::actingAs($owner)->test(TeamOnboarding::class)
        ->assertViewHas('counts', ['members' => 5, 'through' => 2, 'not_signed_in' => 2, 'two_factor_missing' => 1])
        ->assertSee('Link sent')->assertSee('not used yet')->assertSee('Link ran out')->assertSee('Required, off')
        ->assertSee('Waiting to sign in')->assertSee('Two-factor missing')
        ->assertSee('Two-factor sign-in is required for: accountant')
        ->assertSeeHtml('data-member="'.$through->email.'" data-through="yes"')
        ->assertDontSee('Erum Gone');
});

it('sends the link again to a member who has not signed in, and to nobody else', function () {
    Notification::fake();
    $owner = $this->userWithRole('owner');
    $waiting = memberOnTheWay('Asif Waiting', 'sales');
    $working = memberOnTheWay('Dua Through', 'sales', ['last_login_at' => now()->subHour()]);

    Livewire::actingAs($owner)->test(TeamOnboarding::class)
        ->call('sendAgain', $waiting->id)
        ->assertDispatched('toast', type: 'success');
    Notification::assertSentTo($waiting, StaffInvitation::class);
    expect(DB::table('password_reset_tokens')->where('email', $waiting->email)->exists())->toBeTrue();

    // Somebody who already works here gets no link from this screen: the page was older than the record.
    Livewire::actingAs($owner)->test(TeamOnboarding::class)
        ->call('sendAgain', $working->id)
        ->assertDispatched('toast', type: 'warning');
    Notification::assertNotSentTo($working, StaffInvitation::class);

    Livewire::actingAs($this->userWithRole('sales'))->test(TeamOnboarding::class)
        ->call('sendAgain', $waiting->id)
        ->assertForbidden();
});
