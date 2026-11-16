<?php

use App\Support\AdminNavigation;

/*
| The navigation registry, read as data (docs/woodex-os/spec/05-ADMIN-IA-AND-UX.md §2–3). What a person
| actually sees — by role, and by which modules are on — is tested in tests/Feature/FeatureStatusTest.php.
*/

it('keeps the website tools under one Website Studio heading without changing their permissions', function () {
    $studio = array_values(array_filter(AdminNavigation::groups(), fn (array $group): bool => $group['title'] === 'Website Studio'));
    expect($studio)->toHaveCount(1);

    $permissions = array_column($studio[0]['items'], 'permission', 'route');
    foreach ([
        'admin.website.media' => 'website.edit', 'admin.website.seo.overview' => 'website.edit', 'admin.website.seo' => 'website.edit',
        'admin.website.speed' => 'website.edit', 'admin.website.health' => 'website.edit', 'admin.website.theme' => 'theme.manage',
        'admin.website.settings' => 'settings.manage', 'admin.website.pages' => 'website.edit', 'admin.website.builder' => 'website.edit',
        'admin.website.find-replace' => 'website.edit', 'admin.website.redirects' => 'website.edit', 'admin.website.blog' => 'website.edit',
        'admin.website.portfolio' => 'website.edit', 'admin.website.forms' => 'website.edit',
    ] as $route => $permission) {
        expect($permissions[$route] ?? null)->toBe($permission, $route);
    }
});

it('follows the order of groups the owner specified', function () {
    expect(array_column(AdminNavigation::groups(), 'title'))->toBe([
        'Workspace', 'Customers', 'Sales', 'Commerce', 'Operations', 'Procurement & Production',
        'Accounts', 'Growth', 'Website Studio', 'System',
    ]);
});

it('lists every destination exactly once', function () {
    $groups = AdminNavigation::groups();
    $items = array_merge(...array_column($groups, 'items'));
    $routes = array_values(array_filter(array_column($items, 'route')));

    // The exact numbers are asserted on purpose: a destination cannot join or leave the registry without a test.
    expect($items)->toHaveCount(68)
        ->and($routes)->toHaveCount(50)
        ->and(array_unique($routes))->toHaveCount(50, 'a screen is listed twice')
        ->and(array_unique(array_column($items, 'id')))->toHaveCount(68, 'two destinations share an id')
        ->and(array_unique(array_column($groups, 'id')))->toHaveCount(count($groups))
        ->and(array_column($groups, 'title'))->not->toContain(null);

    foreach ($items as $item) {
        // Nothing in the registry is a dead end: a destination has a screen here, or a screen in the
        // Agency OS dashboard, or a wave in which it arrives — except the two preferences screens, which
        // are not scheduled.
        $scheduled = $item['route'] !== null || $item['legacy'] !== null || $item['wave'] !== null;
        expect($scheduled || in_array($item['id'], ['workspace-preferences', 'team-onboarding'], true))->toBeTrue($item['id']);
        expect($item['wave'] === null || isset(AdminNavigation::WAVES[$item['wave']]))->toBeTrue($item['id'].' names a wave the plan does not have');
    }
});

it('keeps the linked project-cost hub beside Projects', function () {
    $items = array_merge(...array_column(AdminNavigation::groups(), 'items'));
    $costs = array_values(array_filter($items, fn (array $item): bool => $item['route'] === 'admin.projects.costs'));

    expect($costs)->toHaveCount(1)
        ->and($costs[0]['label'])->toBe('Vendors & costs')
        ->and($costs[0]['permission'])->toBe('accounts.view');
});
