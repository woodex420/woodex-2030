<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Hash;

/*
| The sign-in limit (document 10 §4; master list T-160): five tries a minute for one e-mail address from one
| network address, and five codes a minute at the second step. The limit was set in FortifyServiceProvider and
| never tested. Each test here fails when the limiter is taken off the route or its key is changed.
*/

const SIGN_IN_PASSWORD = 'Right-horse-battery-22';

function signInAs(string $email, string $password, string $from = '203.0.113.10')
{
    return test()->withServerVariables(['REMOTE_ADDR' => $from])->postJson(route('login.store'), ['email' => $email, 'password' => $password]);
}

beforeEach(function () {
    $this->person = $this->userWithRole('sales', ['email' => 'limit@woodex.test', 'password' => Hash::make(SIGN_IN_PASSWORD)]);
});

it('refuses the sixth try within a minute, and says when to come back', function () {
    foreach (range(1, 5) as $try) {
        signInAs('limit@woodex.test', 'wrong-'.$try)->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    $sixth = signInAs('limit@woodex.test', 'wrong-6');

    $sixth->assertTooManyRequests();
    expect((int) $sixth->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
    $this->assertGuest();
});

it('refuses the right password too while the limit holds, and takes it again a minute later', function () {
    foreach (range(1, 5) as $try) {
        signInAs('limit@woodex.test', 'wrong-'.$try)->assertUnprocessable();
    }

    signInAs('limit@woodex.test', SIGN_IN_PASSWORD)->assertTooManyRequests();
    $this->assertGuest();

    $this->travel(61)->seconds();

    signInAs('limit@woodex.test', SIGN_IN_PASSWORD)->assertOk()->assertJson(['two_factor' => false]);
    $this->assertAuthenticatedAs($this->person);
});

it('counts for one e-mail address from one network address, not for everybody', function () {
    $other = $this->userWithRole('sales', ['email' => 'other@woodex.test', 'password' => Hash::make(SIGN_IN_PASSWORD)]);

    foreach (range(1, 5) as $try) {
        signInAs('limit@woodex.test', 'wrong-'.$try)->assertUnprocessable();
    }
    signInAs('limit@woodex.test', 'wrong-6')->assertTooManyRequests();

    // The same person from another network address is not locked out by somebody else's guessing.
    signInAs('limit@woodex.test', 'wrong-again', from: '198.51.100.7')->assertUnprocessable();

    // Another person from the first address signs in.
    signInAs('other@woodex.test', SIGN_IN_PASSWORD)->assertOk();
    $this->assertAuthenticatedAs($other);
});

it('does not give a second set of tries for the same address written with capital letters', function () {
    foreach (['limit@woodex.test', 'LIMIT@woodex.test', 'Limit@Woodex.Test', 'limit@WOODEX.test', 'lImIt@woodex.test'] as $spelling) {
        signInAs($spelling, 'wrong')->assertUnprocessable();
    }

    signInAs('LIMIT@WOODEX.TEST', SIGN_IN_PASSWORD)->assertTooManyRequests();
    $this->assertGuest();
});

it('answers a browser form with the admin\'s own page, which names no software', function () {
    foreach (range(1, 5) as $try) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->post(route('login.store'), ['email' => 'limit@woodex.test', 'password' => 'wrong-'.$try]);
    }

    $page = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->post(route('login.store'), ['email' => 'limit@woodex.test', 'password' => 'wrong-6']);

    $page->assertTooManyRequests()->assertSee('data-error-page="429"', false)->assertDontSee('Laravel')->assertDontSee('Symfony')->assertDontSee('vendor/');
});

it('limits the codes of the second step as well: five a minute', function () {
    $this->person->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();

    signInAs('limit@woodex.test', SIGN_IN_PASSWORD)->assertOk()->assertJson(['two_factor' => true]);
    $this->assertGuest();

    foreach (range(1, 5) as $try) {
        $this->postJson('/admin/two-factor-challenge', ['code' => '00000'.$try])->assertUnprocessable();
    }

    $this->postJson('/admin/two-factor-challenge', ['code' => '000006'])->assertTooManyRequests();
    $this->assertGuest();
});
