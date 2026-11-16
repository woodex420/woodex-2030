<?php

use App\Support\AdminNavigation;

/*
| The roadmap is told on the Feature status page. The sidebar holds only what can be opened
| (docs/woodex-os/spec/05-ADMIN-IA-AND-UX.md §3: "no repeated Soon badges and no Legacy labels in normal
| navigation"). Until 2026-11-16 the sidebar itself showed switched-off modules as disabled "Soon" entries.
*/

it('keeps switched-off modules out of the sidebar and reports them on Feature status', function () {
    config(['modules.enabled' => 'lead_api']);
    $owner = $this->userWithRole('owner');

    $sidebar = collect(AdminNavigation::for($owner));
    expect($sidebar->pluck('title')->all())->not->toContain('Growth')
        ->and($sidebar->flatMap(fn (array $group): array => $group['items'])->whereNull('route'))->toBeEmpty();

    $status = collect(AdminNavigation::status($owner))->flatMap(fn (array $group): array => $group['items'])->keyBy('id');
    foreach (['conversations', 'chat-bot', 'estimations', 'client-portal', 'automations', 'sequences', 'whatsapp-templates', 'marketing', 'pages', 'page-builder', 'blog', 'forms', 'theme'] as $id) {
        expect($status[$id]['state'])->toBe(AdminNavigation::OFF, $id);
    }
    foreach (['site-overview', 'navigation', 'sections-templates', 'workspace-preferences', 'team-onboarding'] as $id) {
        expect($status[$id]['state'])->toBe(AdminNavigation::PLANNED, $id);
    }

    $this->actingAs($owner)->get('/admin')->assertOk()
        ->assertDontSee('frontend reimagine')
        ->assertDontSee('menu-item-disabled', false);
    $this->get(route('admin.system.features'))->assertOk()
        ->assertSee('Built, switched off')->assertSee('Conversations')->assertSee('Not built yet');

    // Naming a closed module does not open it.
    $this->get('/admin/inbox')->assertNotFound();
    $this->get('/admin/website/pages')->assertNotFound();
});

it('does not advertise settings or automation privileges to sales staff', function () {
    config(['modules.enabled' => 'lead_api']);
    $sales = $this->userWithRole('sales');

    expect(collect(AdminNavigation::for($sales))->pluck('title')->all())->not->toContain('Growth', 'System', 'Accounts');

    $known = collect(AdminNavigation::status($sales))->flatMap(fn (array $group): array => $group['items'])->pluck('id')->all();
    expect($known)->not->toContain('chat-bot', 'automations', 'workspace-preferences', 'team-onboarding', 'settings', 'access-team');
});
