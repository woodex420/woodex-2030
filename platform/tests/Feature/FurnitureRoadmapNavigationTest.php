<?php

use App\Support\AdminNavigation;

it('shows requested roadmap sections while keeping switched-off destinations closed', function () {
    config(['modules.enabled' => 'lead_api']);
    $owner = $this->userWithRole('owner');
    $groups = collect(AdminNavigation::for($owner))->keyBy('title');
    foreach (['Inbox', 'Automation', 'Marketing', 'Workspace', 'Content', 'Pages & builder'] as $title) {
        expect($groups->has($title))->toBeTrue();
        foreach ($groups[$title]['items'] as $item) {
            expect($item['route'])->toBeNull()->and($item['phase'])->toBe('soon');
        }
    }
    $this->actingAs($owner)->get('/admin')->assertOk()
        ->assertSee('Soon')->assertSee('frontend reimagine');
    $this->get('/admin/inbox')->assertNotFound();
    $this->get('/admin/website/pages')->assertNotFound();
});

it('does not advertise settings or automation privileges to sales staff', function () {
    config(['modules.enabled' => 'lead_api']);
    $titles = collect(AdminNavigation::for($this->userWithRole('sales')))->pluck('title')->all();
    expect($titles)->not->toContain('Workspace', 'Automation', 'System');
});
