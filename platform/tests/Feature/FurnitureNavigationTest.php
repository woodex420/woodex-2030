<?php

use App\Support\AdminNavigation;
use App\Support\FurnitureNavigation;

it('marks all fifteen transitional screens as 2030 and leaves unconfigured links disabled', function () {
    config(['woodex.legacy_dashboard_url' => '']);
    $items = collect(FurnitureNavigation::groups())->pluck('items')->flatten(1);
    expect($items)->toHaveCount(15);
    foreach ($items as $item) {
        expect($item['phase'])->toBe('2030')
            ->and($item['route'])->toBeNull()
            ->and($item['url'])->toBeNull();
    }
});

it('links to the existing dashboard base path without sharing authentication', function () {
    config(['woodex.legacy_dashboard_url' => 'https://furniture.example/dashboard/']);
    $owner = $this->userWithRole('owner');
    $this->actingAs($owner)->get('/admin')
        ->assertOk()
        ->assertSee('https://furniture.example/dashboard/catalog', false)
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
        ->not->toContain('Commerce & Fulfilment', 'Procurement & Production');
});

it('explains unavailable links without claiming a native implementation', function () {
    config(['woodex.legacy_dashboard_url' => '']);
    $this->actingAs($this->userWithRole('owner'))->get('/admin')
        ->assertOk()->assertSee('dashboard address not configured')->assertSee('Product Catalog');
});
