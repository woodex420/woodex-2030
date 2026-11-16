<?php

use App\Support\AdminNavigation;

it('groups the seven existing tools under one Website heading without changing their permissions', function () {
    $website = array_values(array_filter(AdminNavigation::groups(), fn (array $group): bool => $group['title'] === 'Website'));

    expect($website)->toHaveCount(1)
        ->and(array_column($website[0]['items'], 'label'))->toBe(['Media', 'SEO', 'SEO agent', 'Speed', 'Site health', 'Theme', 'Website Settings'])
        ->and(array_column($website[0]['items'], 'route'))->toBe(['admin.website.media', 'admin.website.seo.overview', 'admin.website.seo', 'admin.website.speed', 'admin.website.health', 'admin.website.theme', 'admin.website.settings'])
        ->and(array_column($website[0]['items'], 'permission'))->toBe(['website.edit', 'website.edit', 'website.edit', 'website.edit', 'website.edit', 'theme.manage', 'settings.manage']);
});

it('keeps the linked project-cost hub while consolidating accounting into three front doors', function () {
    $groups = AdminNavigation::groups();

    $items = array_merge(...array_column($groups, 'items'));
    $existing = array_filter($items, fn (array $item): bool => $item['route'] !== 'admin.projects.costs');
    $added = array_values(array_filter($items, fn (array $item): bool => $item['route'] === 'admin.projects.costs'));
    expect($items)->toHaveCount(43)
        ->and($existing)->toHaveCount(42)
        ->and($added)->toHaveCount(1)
        ->and($added[0]['label'])->toBe('Vendors & costs')
        ->and($added[0]['permission'])->toBe('accounts.view')
        ->and(array_column($groups, 'title'))->not->toContain(null);
});
