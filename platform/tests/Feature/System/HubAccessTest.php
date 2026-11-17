<?php

declare(strict_types=1);

/*
| Slice G: a System hub opens only for someone who can use at least one of its tabs.
|
| The three System pages are several screens each (users, assignment, canned replies / integrations, import /
| health, audit, changes, alerts, recycle). Their tabs always filtered by permission, but the page itself opened
| for any signed-in colleague, who then saw a shell full of tabs their role could not use.
*/

it('keeps a support user out of every System hub', function () {
    $support = $this->userWithRole('support');

    $this->actingAs($support)->get(route('admin.system.access'))->assertForbidden();
    $this->actingAs($support)->get(route('admin.system.connections'))->assertForbidden();
    $this->actingAs($support)->get(route('admin.system.platform'))->assertForbidden();

    // The rest of their day still works.
    $this->actingAs($support)->get(route('admin.dashboard'))->assertOk();
    $this->actingAs($support)->get(route('admin.leads.index'))->assertOk();
});

it('keeps the accountant out of System too, though the books are theirs', function () {
    $accountant = $this->userWithRole('accountant');

    $this->actingAs($accountant)->get(route('admin.system.access'))->assertForbidden();
    $this->actingAs($accountant)->get(route('admin.system.connections'))->assertForbidden();
    $this->actingAs($accountant)->get(route('admin.system.platform'))->assertForbidden();
});

it('opens each hub for the owner', function () {
    $owner = $this->userWithRole('owner');

    $this->actingAs($owner)->get(route('admin.system.access'))->assertOk();
    $this->actingAs($owner)->get(route('admin.system.connections'))->assertOk();
    $this->actingAs($owner)->get(route('admin.system.platform'))->assertOk();
});

it('opens a hub for a person who can use one of its tabs, not the others', function () {
    $auditor = $this->userWithRole('support');
    $auditor->givePermissionTo('audit.view');

    $this->actingAs($auditor)->get(route('admin.system.platform'))->assertOk();
    $this->actingAs($auditor)->get(route('admin.system.access'))->assertForbidden();

    $importer = $this->userWithRole('support');
    $importer->givePermissionTo('system.import');

    $this->actingAs($importer)->get(route('admin.system.connections'))->assertOk();
    $this->actingAs($importer)->get(route('admin.system.access'))->assertForbidden();

    $settings = $this->userWithRole('support');
    $settings->givePermissionTo('settings.manage');

    $this->actingAs($settings)->get(route('admin.system.access'))->assertOk();
    $this->actingAs($settings)->get(route('admin.system.connections'))->assertForbidden();
});
