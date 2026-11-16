<?php

use App\Support\AdminNavigation;
use App\Support\FurnitureNavigation;

/*
| Work that has not moved into this application yet is still done in the Agency OS dashboard. Those
| screens are named on the Feature status page — never in the sidebar — and linked only when a safe
| address is configured.
*/

it('records which work is still done in the Agency OS dashboard, and claims no screen here for it', function () {
    $items = collect(AdminNavigation::groups())->pluck('items')->flatten(1);
    $elsewhere = $items->filter(fn (array $item): bool => $item['route'] === null && $item['legacy'] !== null);

    expect($elsewhere->pluck('legacy')->all())->toBe([
        'orders', 'deliveries', 'support', 'operations',
        'suppliers', 'purchase-orders', 'goods-receipts', 'supplier-invoices', 'production',
        'stock-ledger', 'warehouses', 'stock-transfers', 'stock-reservations',
    ]);
    foreach ($elsewhere as $item) {
        expect(AdminNavigation::state($item))->toBe(AdminNavigation::EXISTING)
            ->and($item['wave'])->not->toBeNull();
    }
    // The catalogue is native: it has screens here and no link elsewhere.
    foreach (['admin.commerce.overview', 'admin.commerce.products', 'admin.commerce.categories'] as $route) {
        $native = $items->firstWhere('route', $route);
        expect($native['legacy'])->toBeNull()->and(AdminNavigation::state($native))->toBe(AdminNavigation::AVAILABLE);
    }
});

it('links to the existing dashboard from Feature status, not from the sidebar, and shares no sign-in', function () {
    config(['woodex.legacy_dashboard_url' => 'https://furniture.example/dashboard/']);
    $owner = $this->userWithRole('owner');

    $this->actingAs($owner)->get(route('admin.system.features'))
        ->assertOk()
        ->assertSee('https://furniture.example/dashboard/orders', false)
        ->assertSee('Open in the Agency OS dashboard')
        ->assertSee('rel="noopener noreferrer"', false);

    $this->get('/admin')->assertOk()->assertDontSee('https://furniture.example/dashboard/orders', false);
});

it('rejects unsafe or ambiguous dashboard addresses', function (string $base) {
    config(['woodex.legacy_dashboard_url' => $base]);
    expect(FurnitureNavigation::url('orders'))->toBeNull();
    foreach (AdminNavigation::status($this->userWithRole('owner')) as $group) {
        foreach ($group['items'] as $item) {
            expect($item['legacy_url'])->toBeNull();
        }
    }
})->with(['javascript:alert(1)', '//example.com', 'http://example.com', 'https://user:pass@example.com', 'https://example.com/?next=x', 'https://example.com/#x']);

it('tells a guest nothing', function () {
    expect(AdminNavigation::for(null))->toBe([])
        ->and(AdminNavigation::status(null))->toBe([]);
    $this->get(route('admin.system.features'))->assertRedirect();
});

it('says where the work is done without claiming a screen here, when no address is configured', function () {
    config(['woodex.legacy_dashboard_url' => '']);
    $this->actingAs($this->userWithRole('owner'))->get(route('admin.system.features'))
        ->assertOk()->assertSee('dashboard address not configured')->assertSee('Purchase Orders')
        ->assertDontSee('Open in the Agency OS dashboard');
});
