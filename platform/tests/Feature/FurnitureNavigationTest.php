<?php

use App\Support\AdminNavigation;
use App\Support\FurnitureNavigation;

it('keeps legacy screens honest and links native categories', function () {
    config(['woodex.legacy_dashboard_url' => '']);
    $items = collect(FurnitureNavigation::groups())->pluck('items')->flatten(1);
    expect($items)->toHaveCount(18);
    expect(collect(FurnitureNavigation::groups())->pluck('title')->all())->toContain('Commerce')->not->toContain('Commerce & Fulfilment');
    foreach ($items as $item) {
        if (in_array($item['route'], ['admin.commerce.categories', 'admin.commerce.products', 'admin.commerce.overview'], true)) {
            expect($item['phase'])->toBeNull();

            continue;
        }
        if ($item['route']) {
            expect($item['phase'])->toBe('soon');

            continue;
        }
        expect($item['phase'])->toBeIn(['2030', 'soon'])
            ->and($item['route'])->toBeNull()
            ->and($item['url'])->toBeNull();
    }
});

it('links to the existing dashboard base path without sharing authentication', function () {
    config(['woodex.legacy_dashboard_url' => 'https://furniture.example/dashboard/']);
    $owner = $this->userWithRole('owner');
    $this->actingAs($owner)->get('/admin')
        ->assertOk()
        ->assertSee('https://furniture.example/dashboard/orders', false)
        ->assertSee('2030 ↗')
        ->assertSee('rel="noopener noreferrer"', false);
});

it('rejects unsafe or ambiguous dashboard addresses', function (string $base) {
    config(['woodex.legacy_dashboard_url' => $base]);
    foreach (FurnitureNavigation::groups() as $group) {
        foreach ($group['items'] as $item) {
            expect($item['url'])->toBeNull();
        }
    }
})->with(['javascript:alert(1)', '//example.com', 'http://example.com', 'https://user:pass@example.com', 'https://example.com/?next=x', 'https://example.com/#x']);

it('does not expose transitional navigation to guests', function () {
    expect(collect(AdminNavigation::for(null))->pluck('title')->all())
        ->not->toContain('Commerce', 'Procurement & Production');
});

it('explains unavailable links without claiming a native implementation', function () {
    config(['woodex.legacy_dashboard_url' => '']);
    $this->actingAs($this->userWithRole('owner'))->get('/admin')
        ->assertOk()->assertSee('dashboard address not configured')->assertSee('Products');
});
