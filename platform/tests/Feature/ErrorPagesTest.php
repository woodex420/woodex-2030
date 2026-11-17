<?php

use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
| The error pages are the admin's own (resources/views/errors), in the design of the sign-in page.
| Until 2026-11-17 a wrong address, a screen without permission and an expired form showed the framework's
| bare pages ("Not Found", "Forbidden", "Page Expired").
*/

it('answers an unknown admin address with its own page and a way back to the dashboard', function () {
    config(['modules.enabled' => 'lead_api']);

    $this->actingAs($this->userWithRole('sales'))
        ->get('/admin/no-such-screen')
        ->assertNotFound()
        ->assertSee('This page does not exist')
        ->assertSee('data-error-page="404"', false)
        ->assertSee('href="'.route('admin.dashboard').'"', false)
        ->assertSee('Go to the dashboard')
        ->assertDontSee('Stack trace');
});

it('answers an unknown address outside the admin with a way to the front page', function () {
    config(['modules.enabled' => 'lead_api']);

    $this->get('/no-such-page')
        ->assertNotFound()
        ->assertSee('This page does not exist')
        ->assertSee('Go to the front page');
});

it('answers a switched-off module with the same page', function () {
    config(['modules.enabled' => 'lead_api']);

    $this->actingAs($this->userWithRole('owner'))
        ->get('/admin/automation')
        ->assertNotFound()
        ->assertSee('switched off here');
});

it('says in plain words that a role has no access, and gives the reason where the application wrote one', function () {
    $sales = $this->userWithRole('sales');

    $this->actingAs($sales)->get(route('admin.system.health'))
        ->assertForbidden()
        ->assertSee('You do not have access to this screen')
        ->assertSee('ask the owner to change your role')
        ->assertDontSee('This action is unauthorized');

    $this->actingAs($sales)->get('/admin?tab=system')
        ->assertForbidden()
        ->assertSee('That tab is not available to you.');
});

it('hides what went wrong inside from the person who sees the error page', function () {
    config(['app.debug' => false]);
    Route::get('/_test/boom', fn () => throw new RuntimeException('SQLSTATE secret table name'));

    $this->get('/_test/boom')
        ->assertStatus(500)
        ->assertSee('Something went wrong on our side')
        ->assertSee('Pakistan time')
        ->assertDontSee('SQLSTATE')
        ->assertDontSee('RuntimeException');
});

it('has a page for every status the admin answers with', function (int $status, string $heading) {
    $html = view("errors.{$status}", ['exception' => new HttpException($status)])->render();

    expect($html)->toContain($heading)
        ->and($html)->toContain('data-error-page="'.$status.'"')
        ->and($html)->toContain('wx-auth-card')
        ->and($html)->toContain('noindex, nofollow');
})->with([
    [403, 'You do not have access to this screen'],
    [404, 'This page does not exist'],
    [419, 'This page was open for too long'],
    [429, 'Too many attempts in a short time'],
    [500, 'Something went wrong on our side'],
    [503, 'WOODEX OS is unavailable for a moment'],
]);
