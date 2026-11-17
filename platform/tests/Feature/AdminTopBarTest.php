<?php

use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Support\AdminNavigation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| The top bar of the admin (docs/woodex-os/00-MASTER-PLAN.md §7.2, slice 1.4): where you are, search, "New",
| notifications, the user menu. One row at every width — the browser check for that is scripts/os-screens.mjs,
| which measures the bar on every screen at 390 px.
*/

it('offers in the New menu only what the person may start', function (string $role, array $expected) {
    config(['modules.enabled' => 'lead_api']);
    $user = $this->userWithRole($role);

    expect(array_column(AdminNavigation::quickCreate($user), 'id'))->toBe($expected);

    // Every entry opens for this person: the menu and the route agree.
    $this->actingAs($user);
    foreach (AdminNavigation::quickCreate($user) as $entry) {
        $this->get($entry['url'])->assertOk();
    }
})->with([
    'owner' => ['owner', ['lead', 'quotation', 'invoice', 'product']],
    'sales' => ['sales', ['lead', 'quotation']],
    'accountant' => ['accountant', ['invoice']],
    'support' => ['support', ['lead']],
    'editor' => ['editor', []],
]);

it('adds New estimate only while the estimator module is on', function () {
    $owner = $this->userWithRole('owner');

    config(['modules.enabled' => 'lead_api']);
    expect(array_column(AdminNavigation::quickCreate($owner), 'id'))->not->toContain('estimate');

    config(['modules.enabled' => 'lead_api,estimator']);
    expect(array_column(AdminNavigation::quickCreate($owner), 'id'))->toContain('estimate');
    $this->actingAs($owner)->get(route('admin.estimations.create'))->assertOk();
});

it('offers nothing to somebody who is not signed in', function () {
    expect(AdminNavigation::quickCreate(null))->toBe([])
        ->and(AdminNavigation::location(null, Request::create('/admin/leads')))->toBeNull();
});

it('shows the New menu, the theme choice and the user menu in the bar', function () {
    $this->actingAs($this->userWithRole('sales'));

    $html = $this->get(route('admin.leads.index'))->assertOk()
        ->assertSee('data-quick-create="lead"', false)
        ->assertSee('data-quick-create="quotation"', false)
        ->assertDontSee('data-quick-create="invoice"', false)
        ->assertSee('data-theme-choice="dark"', false)
        ->assertSee('aria-label="Create new"', false)
        ->assertSee('Sign out')
        ->getContent();

    // One row: the bar has one fixed height and nothing in it may wrap to a second row.
    preg_match('/<header class="([^"]*wx-admin-header[^"]*)"/', $html, $header);
    expect($header[1] ?? '')->toContain('h-(--wx-topbar-h)')
        ->and($header[1] ?? '')->not->toContain('flex-wrap');
    // The theme used to be a <select> in the bar itself, which is what pushed it into two rows on a phone.
    expect($html)->not->toMatch('/<select[^>]*aria-label="Color theme"/');
});

it('leaves the New button out for somebody who can start nothing', function () {
    $this->actingAs($this->userWithRole('editor'))
        ->get(route('admin.dashboard'))->assertOk()
        ->assertDontSee('aria-label="Create new"', false);
});

it('says where a screen of the menu is: its group and its name', function () {
    $user = $this->userWithRole('owner');
    $request = fn (string $name): Request => tap(Request::create(route($name, [], false)), function (Request $request): void {
        $request->setRouteResolver(fn () => Route::getRoutes()->match($request));
    });

    expect(AdminNavigation::location($user, $request('admin.leads.index')))
        ->toBe(['group' => 'Customers', 'label' => 'Leads', 'url' => route('admin.leads.index'), 'here' => true])
        ->and(AdminNavigation::location($user, $request('admin.profile.security')))->toBeNull();

    $this->actingAs($user)->get(route('admin.approvals'))->assertOk()
        ->assertSee('aria-label="Breadcrumb"', false);
});

it('puts the trail of a record page in the bar and keeps one way back on the page', function () {
    $user = $this->userWithRole('owner');
    $lead = Lead::factory()->for(Contact::factory()->create(['name' => 'Bilal Chaudhry']))->create(['ref' => 'L-2026-000321']);

    $html = $this->actingAs($user)->get(route('admin.leads.show', $lead))->assertOk()->getContent();

    preg_match('/<nav class="wx-topbar-trail[^"]*"[^>]*>(.*?)<\/nav>/s', $html, $trail);
    expect($trail[1] ?? '')->toContain('L-2026-000321')
        ->and($trail[1] ?? '')->toContain('href="'.route('admin.leads.index').'"');
    // On a phone the bar has no room for the trail: the page keeps the link to the step above.
    expect($html)->toMatch('/<a href="'.preg_quote(route('admin.leads.index'), '/').'"[^>]*class="wx-page-back[^"]*"/');
    // The trail is written once, not also above the heading as before.
    expect(substr_count($html, 'aria-label="Breadcrumb"'))->toBe(1);
});
