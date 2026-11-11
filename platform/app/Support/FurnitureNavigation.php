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
                array_splice($items, 1, 0, [[
                    'label' => 'Categories', 'icon' => 'folder', 'route' => 'admin.commerce.categories',
                    'permission' => 'commerce.catalog.view', 'phase' => null, 'url' => null,
                ]]);
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
