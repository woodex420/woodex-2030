<?php

use App\Support\TwoWritersMet;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
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

it('tells a person that somebody else was saving, when the database ended the request for another writer', function (string $fault) {
    config(['app.debug' => false]);
    Route::get('/_test/two-writers', fn () => throw match ($fault) {
        'a deadlock in the request\'s own transaction' => new QueryException('mysql', 'select * from `number_sequences` where `key` = ? limit 1 for update', ['journal'], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction')),
        'a deadlock inside a nested transaction' => new DeadlockException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction (SQL: update `number_sequences`)'),
        'a wait for a lock that ran out' => new QueryException('mysql', 'update `number_sequences` set `next_number` = ?', [2], new PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction')),
        'SQLite, busy with another writer' => new QueryException('sqlite', 'update "number_sequences" set "next_number" = ?', [2], new PDOException('SQLSTATE[HY000]: General error: 5 database is locked')),
        'a deadlock wrapped by the code that met it' => new RuntimeException('The posting failed.', 0, new DeadlockException('Deadlock found when trying to get lock')),
    });

    $this->get('/_test/two-writers')
        ->assertStatus(409)
        ->assertSee('Somebody else was saving the same records')
        ->assertSee('Nothing of what you just did was saved')
        ->assertSee('data-error-page="409"', false)
        ->assertDontSee('SQLSTATE')
        ->assertDontSee('number_sequences')
        ->assertDontSee('Something went wrong on our side');

    $this->getJson('/_test/two-writers')
        ->assertStatus(409)
        ->assertExactJson(['message' => TwoWritersMet::MESSAGE]);
})->with([
    'a deadlock in the request\'s own transaction',
    'a deadlock inside a nested transaction',
    'a wait for a lock that ran out',
    'SQLite, busy with another writer',
    'a deadlock wrapped by the code that met it',
]);

it('keeps every other fault of the database an error on our side', function () {
    config(['app.debug' => false]);
    Route::get('/_test/not-two-writers', fn () => throw new QueryException('mysql', 'insert into `number_sequences`', [], new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry')));

    $this->get('/_test/not-two-writers')
        ->assertStatus(500)
        ->assertSee('Something went wrong on our side')
        ->assertDontSee('Duplicate entry');
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
    [409, 'Somebody else was saving the same records'],
    [419, 'This page was open for too long'],
    [429, 'Too many attempts in a short time'],
    [500, 'Something went wrong on our side'],
    [503, 'WOODEX OS is unavailable for a moment'],
]);
