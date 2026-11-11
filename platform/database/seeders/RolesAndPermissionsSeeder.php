<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Default roles and permissions (docs/02-MASTER-PRD.md §7, docs/07 D-21). Owners bypass checks via Gate::before.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /** @var list<string> */
    public const PERMISSIONS = [
        'dashboard.view',
        'leads.view', 'leads.view_all', 'leads.create', 'leads.update', 'leads.assign', 'leads.delete', 'leads.export',
        'contacts.view', 'contacts.update', 'contacts.merge',
        'quotes.view', 'quotes.manage', 'quotes.send', 'quotes.approve', 'quotes.catalog',
        'estimates.view', 'estimates.manage',
        'invoices.view', 'invoices.manage', 'invoices.void',
        'payments.record', 'payments.verify',
        'accounts.view', 'accounts.manage',
        'projects.view', 'projects.manage',
        'inbox.view', 'inbox.reply',
        'automation.manage',
        'website.edit', 'website.publish', 'website.raw_html', 'theme.manage',
        'social.manage',
        'reports.view', 'reports.finance',
        'settings.manage', 'users.manage', 'integrations.manage',
        'system.health', 'system.change_requests', 'system.import', 'audit.view', 'backups.manage',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $all = self::PERMISSIONS;

        $roles = [
            'owner' => $all,
            'admin' => array_values(array_diff($all, ['quotes.approve', 'invoices.void', 'accounts.manage', 'website.raw_html'])),
            'sales_manager' => [
                'dashboard.view',
                'leads.view', 'leads.view_all', 'leads.create', 'leads.update', 'leads.assign', 'leads.export',
                'contacts.view', 'contacts.update', 'contacts.merge',
                'quotes.view', 'quotes.manage', 'quotes.send', 'quotes.approve', 'quotes.catalog', 'estimates.view', 'estimates.manage',
                'invoices.view', 'projects.view', 'projects.manage',
                'inbox.view', 'inbox.reply', 'automation.manage',
                'website.edit', 'website.publish', 'social.manage', 'reports.view',
            ],
            'sales' => [
                'dashboard.view', 'leads.view', 'leads.create', 'leads.update',
                'contacts.view', 'contacts.update',
                'quotes.view', 'quotes.manage', 'quotes.send', 'estimates.view', 'estimates.manage',
                'invoices.view', 'projects.view', 'inbox.view', 'inbox.reply', 'reports.view',
            ],
            'designer_pm' => [
                'dashboard.view', 'leads.view', 'contacts.view', 'quotes.view', 'quotes.manage', 'estimates.view', 'estimates.manage',
                'projects.view', 'projects.manage', 'inbox.view', 'inbox.reply',
            ],
            'accountant' => [
                'dashboard.view', 'contacts.view', 'quotes.view', 'estimates.view',
                'invoices.view', 'invoices.manage', 'payments.record', 'payments.verify',
                'projects.view', 'reports.view', 'reports.finance', 'leads.export',
            ],
            'editor' => ['dashboard.view', 'website.edit', 'social.manage'],
            'support' => ['dashboard.view', 'leads.view', 'leads.create', 'contacts.view', 'inbox.view', 'inbox.reply'],
        ];

        foreach ($roles as $name => $permissions) {
            Role::findOrCreate($name, 'web')->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
