<?php

declare(strict_types=1);

namespace App\Support;

/** Transitional links only. The existing dashboard authenticates and authorizes independently. */
final class FurnitureNavigation
{
    public static function groups(): array
    {
        $sections = [
            'Commerce' => [
                ['Products', 'catalog'],
                ['Orders', 'orders'],
                ['Delivery & Installation', 'deliveries'],
                ['Support & Warranty', 'support'],
                ['Operations', 'operations'],
            ],
            'Procurement & Production' => [
                ['Suppliers', 'suppliers'],
                ['Purchase Orders', 'purchase-orders'],
                ['Goods Receipts', 'goods-receipts'],
                ['Supplier Invoices', 'supplier-invoices'],
                ['Production', 'production'],
                ['Stock Ledger', 'stock-ledger'],
                ['Warehouses', 'warehouses'],
                ['Stock Transfers', 'stock-transfers'],
                ['Reservations', 'stock-reservations'],
            ],
        ];

        $groups = [];
        foreach ($sections as $title => $entries) {
            $items = [];
            foreach ($entries as [$label, $path]) {
                $items[] = [
                    'label' => $label, 'icon' => 'external', 'route' => null,
                    'permission' => 'dashboard.view', 'phase' => $title === 'Commerce' ? 'Legacy' : '2030',
                    'url' => self::url($path),
                ];
            }
            if ($title === 'Commerce') {
                $items = array_values(array_filter($items, fn ($item) => $item['label'] !== 'Products'));
                foreach ($items as &$item) {
                    $item['phase'] = 'soon';
                }
                unset($item);
                $native = [];
                foreach ([['Commerce Overview', 'overview', null], ['Products', 'products', null], ['Categories', 'categories', null], ['Series', 'series', 'soon'], ['Shop customization', 'shop', 'soon']] as [$label, $route, $phase]) {
                    $native[] = ['label' => $label, 'icon' => 'folder', 'route' => 'admin.commerce.'.$route, 'permission' => 'commerce.catalog.view', 'phase' => $phase, 'url' => null];
                }
                $items = array_merge($native, $items);
            }
            $groups[] = ['title' => $title, 'items' => $items];
        }

        return $groups;
    }

    private static function url(string $path): ?string
    {
        $base = rtrim((string) config('woodex.legacy_dashboard_url'), '/');
        $parts = parse_url($base);

        // Operator-controlled HTTPS URL, including the dashboard base path. Never accept script URLs or credentials.
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || filter_var($base, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $base.'/'.$path;
    }
}
