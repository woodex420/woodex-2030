<?php

use App\Livewire\Admin\Accounts\ChartOfAccounts;
use App\Livewire\Admin\Accounts\JournalsIndex;
use App\Livewire\Admin\Accounts\MethodAccounts;
use App\Livewire\Admin\Accounts\PeriodsIndex;
use App\Support\AdminNavigation;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

it('grants the accountant read access without ledger management', function () {
    $user = $this->userWithRole('accountant');
    expect($user->can('accounts.view'))->toBeTrue()
        ->and($user->can('accounts.manage'))->toBeFalse();
    $titles = collect(AdminNavigation::for($user))->pluck('title')->all();
    expect($titles)->toContain('Accounts');
    $this->actingAs($user)->followingRedirects()->get('/admin/accounts')->assertOk();
    $this->get('/admin/accounts/trial-balance')->assertOk();
    $this->get('/admin/accounts/profit-loss')->assertOk();
});

it('denies accountant mutations at the server action boundary', function (string $component, string $action) {
    Livewire::actingAs($this->userWithRole('accountant'))->test($component)
        ->call($action)->assertForbidden();
})->with([
    [ChartOfAccounts::class, 'create'],
    [ChartOfAccounts::class, 'save'],
    [JournalsIndex::class, 'startEntry'],
    [JournalsIndex::class, 'post'],
    [PeriodsIndex::class, 'create'],
    [PeriodsIndex::class, 'save'],
    [MethodAccounts::class, 'save'],
]);

it('does not grant ledger viewing to sales or support', function (string $role) {
    $user = $this->userWithRole($role);
    expect($user->can('accounts.view'))->toBeFalse();
    $this->actingAs($user)->get('/admin/accounts')->assertForbidden();
})->with(['sales', 'support']);

it('upgrades an existing accountant role idempotently without removing custom grants', function () {
    $role = Role::findByName('accountant', 'web');
    $role->revokePermissionTo('accounts.view');
    $role->givePermissionTo('audit.view');
    $migration = require database_path('migrations/2026_11_11_210000_grant_accountant_ledger_read_access.php');
    $migration->up();
    $migration->up();
    $role->refresh();
    expect($role->hasPermissionTo('accounts.view'))->toBeTrue()
        ->and($role->hasPermissionTo('audit.view'))->toBeTrue()
        ->and($role->hasPermissionTo('accounts.manage'))->toBeFalse();
});
