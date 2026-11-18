<?php

use App\Livewire\Admin\System\FeatureStatus;
use App\Models\User;
use App\Support\AdminNavigation;
use Illuminate\Support\Facades\Route;

/*
| The sidebar and the Feature status page are two views of one registry
| (docs/woodex-os/spec/05-ADMIN-IA-AND-UX.md §3 and §10):
|   - the sidebar shows only permitted and available screens;
|   - no destination appears twice;
|   - what is not available is told on Feature status, with where the work is done today.
*/

it('opens every screen the sidebar offers, for each role', function (string $role) {
    $user = $this->userWithRole($role);
    $this->actingAs($user);

    $items = collect(AdminNavigation::for($user))->flatMap(fn (array $group): array => $group['items']);
    expect($items)->not->toBeEmpty();

    $refused = [];
    foreach ($items as $item) {
        $status = $this->followingRedirects()->get(route($item['route']))->getStatusCode();
        if ($status !== 200) {
            $refused[] = $item['label'].' → '.$status;
        }
    }

    expect($refused)->toBe([], "the sidebar of the {$role} role offers screens that do not open");
})->with(['owner', 'sales_manager', 'sales', 'accountant']);

it('offers a sales person no screen that would refuse them, and refuses the ones it leaves out', function () {
    $sales = $this->userWithRole('sales');
    $offered = collect(AdminNavigation::for($sales))->flatMap(fn (array $group): array => $group['items'])->pluck('route')->all();

    expect($offered)->toContain('admin.dashboard', 'admin.leads.index', 'admin.quotes.index', 'admin.system.features')
        ->and($offered)->not->toContain('admin.settings', 'admin.system.access', 'admin.accounts.workspace', 'admin.automation.index');

    $this->actingAs($sales);
    foreach (['admin.settings', 'admin.system.access', 'admin.accounts.workspace', 'admin.automation.index', 'admin.system.audit'] as $route) {
        $this->get(route($route))->assertForbidden();
    }
});

it('shows each destination once and never offers a closed or missing screen', function () {
    config(['modules.enabled' => 'lead_api']);
    $owner = $this->userWithRole('owner');

    $routes = collect(AdminNavigation::for($owner))->flatMap(fn (array $group): array => $group['items'])->pluck('route');
    expect($routes->duplicates()->all())->toBe([])
        ->and($routes->filter(fn (?string $route): bool => $route === null || ! Route::has($route))->all())->toBe([])
        ->and($routes->all())->not->toContain('admin.inbox', 'admin.estimations.index', 'admin.website.pages', 'admin.marketing.index');

    // Nothing is lost: what the sidebar leaves out is on Feature status, and together they are the registry.
    $registry = collect(AdminNavigation::registry())->flatMap(fn (array $group): array => $group['items'])->pluck('id');
    $status = collect(AdminNavigation::status($owner))->flatMap(fn (array $group): array => $group['items']);
    expect($status->pluck('id')->all())->toBe($registry->all());
    expect($status->where('state', AdminNavigation::AVAILABLE)->pluck('route')->sort()->values()->all())->toBe($routes->sort()->values()->all());
});

it('renders Feature status with the state of every part and its counts', function () {
    config(['modules.enabled' => 'lead_api', 'woodex.legacy_dashboard_url' => '']);
    $owner = $this->userWithRole('owner');
    $status = collect(AdminNavigation::status($owner))->flatMap(fn (array $group): array => $group['items']);

    $response = $this->actingAs($owner)->get(route('admin.system.features'))->assertOk()->assertSeeLivewire(FeatureStatus::class);
    foreach ([AdminNavigation::AVAILABLE, AdminNavigation::EXISTING, AdminNavigation::OFF, AdminNavigation::PLANNED] as $state) {
        $count = $status->where('state', $state)->count();
        expect($count)->toBeGreaterThan(0);
        $response->assertSee('data-feature-count="'.$state.'">'.$count.'<', false);
    }
    $response->assertSee('data-feature="orders" data-state="existing"', false)
        ->assertSee('Arrives here in wave 6 — Orders, delivery, support.')
        ->assertSee('data-feature="conversations" data-state="off"', false)
        ->assertSee('data-feature="navigation" data-state="planned"', false)
        ->assertSee('data-feature="leads" data-state="available"', false);
});

it('tells each person only about what their role could use', function () {
    $accountant = $this->userWithRole('accountant');
    $known = collect(AdminNavigation::status($accountant))->flatMap(fn (array $group): array => $group['items'])->pluck('id')->all();

    expect($known)->toContain('ledger-reports', 'payables', 'feature-status', 'workspace-preferences');
    // One by one: "not to contain a, b, c" holds as soon as any one of them is missing.
    foreach (['settings', 'access-team', 'chat-bot', 'team-onboarding'] as $id) {
        expect($known)->not->toContain($id);
    }

    $this->actingAs($accountant)->get(route('admin.system.features'))->assertOk()
        ->assertSee('Ledger &amp; reports', false)->assertSee('data-feature="workspace-preferences"', false)
        // The news of a release may name a screen; the list of destinations names only what this role could use.
        ->assertDontSee('data-feature="team-onboarding"', false);
});

it('keeps Feature status closed to someone with no access to the admin', function () {
    $nobody = User::factory()->create();
    $this->actingAs($nobody)->get(route('admin.system.features'))->assertForbidden();
});
