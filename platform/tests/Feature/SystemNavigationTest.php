<?php

declare(strict_types=1);

use App\Support\AdminNavigation;

/*
| Deep QA 21 Oct, slice B: the sidebar told the wrong story. Client Portal, Handovers and the Chat
| Bot were shipped but shown as "P4b/P4"; Alert Rules appeared twice; and System listed eleven
| screens. The nav now points at screens that exist, the eleven became four, and the old routes
| still answer for bookmarks and Telegram buttons.
*/

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
    $this->support = $this->userWithRole('support');
});

it('shows shipped screens as live destinations instead of future phases', function () {
    $items = collect(AdminNavigation::for($this->owner))
        ->flatMap(fn (array $group): array => $group['items'])
        ->keyBy('label');

    expect($items['Client portal']['route'])->toBe('admin.projects.portal')
        ->and($items['Handovers']['route'])->toBe('admin.projects.handovers')
        ->and($items['Chat Bot']['route'])->toBe('admin.chat-bot')
        ->and($items['Client portal']['phase'] ?? null)->toBeNull()
        ->and($items['Handovers']['phase'] ?? null)->toBeNull()
        ->and($items['Chat Bot']['phase'] ?? null)->toBeNull();
});

it('folds the eleven System entries into four live pages with their buttons inside', function () {
    $system = collect(AdminNavigation::for($this->owner))->firstWhere('title', 'System');

    expect(collect($system['items'])->pluck('label')->all())
        ->toBe(['Settings', 'Access & team', 'Connections', 'Platform'])
        ->and(collect($system['items'])->whereNull('route'))->toBeEmpty();
});

it('does not advertise any unimplemented planned entry — no shipped module wears a phase badge', function () {
    $planned = collect(AdminNavigation::for($this->owner))
        ->flatMap(fn (array $group): array => $group['items'])
        ->filter(fn (array $item): bool => $item['route'] === null)
        ->pluck('label')
        ->values()
        ->all();

    expect($planned)->toBe([]);
});

it('opens the four System pages for the owner', function (string $path, string $marker) {
    $this->actingAs($this->owner)->get($path)->assertOk()->assertSee($marker);
})->with([
    'access' => ['/admin/system/access', 'Users & roles'],
    'connections' => ['/admin/system/connections', 'Integrations'],
    'platform' => ['/admin/system/platform', 'Health'],
    'settings' => ['/admin/settings', 'Settings'],
]);

it('keeps every System page closed to a role without the permissions', function (string $path) {
    $this->actingAs($this->support)->get($path)->assertForbidden();
})->with([
    'access' => ['/admin/system/access'],
    'connections' => ['/admin/system/connections'],
    'platform' => ['/admin/system/platform'],
]);

it('opens the client portal links and the handover list for the owner', function () {
    $this->actingAs($this->owner)->get('/admin/projects/portal')->assertOk()->assertSee('Portal links');
    $this->actingAs($this->owner)->get('/admin/projects/handovers')->assertOk()->assertSee('Handovers');
});

it('sends the Chat Bot sidebar entry to its real switches on Settings', function () {
    $this->actingAs($this->owner)
        ->get('/admin/chat-bot')
        ->assertRedirect(route('admin.settings', ['tab' => 'bot']));
});
