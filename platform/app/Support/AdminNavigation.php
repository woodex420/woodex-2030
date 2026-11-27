<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Crm\Models\Lead;
use App\Domain\System\Control\OperationsSummary;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The one navigation registry of the admin (docs/woodex-os/spec/05-ADMIN-IA-AND-UX.md §2–3).
 *
 * Every destination of WOODEX OS is listed here once, in the order the owner's specification gives,
 * whether or not it exists in this application yet. Two views are made from it:
 *
 *  - {@see self::for()} — the sidebar and the command palette. Only what the signed-in person may use
 *    and can open today. Nothing disabled, no "Soon", no "Legacy".
 *  - {@see self::status()} — the Feature status screen. Everything the person is allowed to know about,
 *    with its state and, for work still done in the Agency OS dashboard, where that is.
 *
 * Until 2026-11-16 the sidebar itself carried the roadmap as disabled "Soon" and "2030" entries. The
 * owner's specification moved that to its own screen; the decision is DL-009 in
 * docs/woodex-os/12-DECISION-LOG.md.
 */
final class AdminNavigation
{
    /**
     * Routes that answer with a full HTML document of their own (the Visual Page Builder). Livewire's
     * `wire:navigate` swaps only the body, so navigating there from the sidebar would drop the builder's
     * stylesheet and script — these links must do a normal page load.
     *
     * @var list<string>
     */
    public const array FULL_DOCUMENT = ['admin.website.builder', 'admin.website.blog', 'admin.website.portfolio', 'admin.website.forms'];

    /** The screen exists here and its module is on. */
    public const string AVAILABLE = 'available';

    /** Not built here yet; the work is done in the Agency OS dashboard. */
    public const string EXISTING = 'existing';

    /** Built here, but its module is switched off. */
    public const string OFF = 'off';

    /** Not built anywhere yet. */
    public const string PLANNED = 'planned';

    /**
     * Migration waves of docs/woodex-os/00-MASTER-PLAN.md §6, for the Feature status screen.
     *
     * @var array<int, string>
     */
    public const array WAVES = [
        4 => 'Sales documents',
        5 => 'Catalogue and media',
        6 => 'Orders, delivery, support',
        7 => 'Stock and purchasing',
        8 => 'Production',
        9 => 'Projects, finance, reports',
        10 => 'Website Studio and growth',
    ];

    /**
     * The canonical list. A group or an item keeps its id for good: saved sidebar state, the command
     * palette and tests refer to it.
     *
     * `legacy` is the path of the screen in the Agency OS dashboard that does this job today, where one
     * exists. `wave` is the migration wave in which the destination arrives here.
     *
     * @return list<array{id: string, title: string, items: list<array{id: string, label: string, icon: string, route: string|null, permission: string, legacy: string|null, wave: int|null, phase: null, url: null}>}>
     */
    public static function registry(): array
    {
        return [
            self::group('workspace', 'Workspace', [
                self::item('dashboard', 'Dashboard', 'dashboard', 'admin.dashboard', 'dashboard.view'),
                self::item('approvals', 'Approvals', 'check-circle', 'admin.approvals', 'quotes.approve'),
                self::item('reports', 'Reports', 'chart', 'admin.reports.index', 'reports.view'),
            ]),
            self::group('customers', 'Customers', [
                self::item('leads', 'Leads', 'users', 'admin.leads.index', 'leads.view'),
                self::item('contacts', 'Contacts', 'contact', 'admin.contacts.index', 'contacts.view'),
                self::item('tasks-visits', 'Tasks & Visits', 'tasks', 'admin.tasks.index', 'leads.view'),
                self::item('conversations', 'Conversations', 'inbox', 'admin.inbox', 'inbox.view', 'omnichannel', 10),
                self::item('chat-bot', 'Chat Bot', 'bot', 'admin.chat-bot', 'settings.manage', null, 10),
            ]),
            self::group('sales', 'Sales', [
                self::item('estimations', 'Estimations', 'calculator', 'admin.estimations.index', 'estimates.view', null, 4),
                self::item('quotations', 'Quotations', 'quote', 'admin.quotes.index', 'quotes.view'),
                self::item('invoices', 'Invoices', 'invoice', 'admin.invoices.index', 'invoices.view'),
                self::item('payments', 'Payments', 'wallet', 'admin.payments.index', 'invoices.view'),
                self::item('documents', 'Documents', 'book', 'admin.sales.documents', 'quotes.view'),
            ]),
            self::group('commerce', 'Commerce', [
                self::item('store-overview', 'E-commerce', 'folder', 'admin.commerce.overview', 'commerce.catalog.view'),
                self::item('products', 'Products', 'folder', 'admin.commerce.products', 'commerce.catalog.view'),
                self::item('categories', 'Categories', 'folder', 'admin.commerce.categories', 'commerce.catalog.view'),
                self::item('series', 'Series', 'folder', 'admin.commerce.series', 'commerce.catalog.view'),
                self::item('orders', 'Orders', 'invoice', null, 'dashboard.view', 'orders', 6),
                self::item('delivery', 'Delivery & Installation', 'calendar', null, 'dashboard.view', 'deliveries', 6),
                self::item('support', 'Support & Warranty', 'shield', null, 'dashboard.view', 'support', 6),
            ]),
            self::group('operations', 'Operations', [
                self::item('operations-board', 'Operations board', 'kanban', null, 'dashboard.view', 'operations', 8),
                self::item('projects', 'Projects', 'hammer', 'admin.projects.index', 'projects.view'),
                self::item('project-board', 'Board', 'kanban', 'admin.projects.board', 'projects.view'),
                self::item('vendors-costs', 'Vendors & costs', 'wallet', 'admin.projects.costs', 'accounts.view'),
                self::item('client-portal', 'Client portal', 'link', 'admin.projects.portal', 'projects.view', null, 9),
                self::item('handovers', 'Handovers', 'check-circle', 'admin.projects.handovers', 'projects.view'),
            ]),
            self::group('procurement-production', 'Procurement & Production', [
                self::item('suppliers', 'Suppliers', 'users', null, 'dashboard.view', 'suppliers', 7),
                self::item('purchase-orders', 'Purchase Orders', 'invoice', null, 'dashboard.view', 'purchase-orders', 7),
                self::item('goods-receipts', 'Goods Receipts', 'check-circle', null, 'dashboard.view', 'goods-receipts', 7),
                self::item('supplier-invoices', 'Supplier Invoices', 'invoice', null, 'dashboard.view', 'supplier-invoices', 7),
                self::item('production', 'Production', 'hammer', null, 'dashboard.view', 'production', 8),
                self::item('stock-ledger', 'Stock Ledger', 'book', null, 'dashboard.view', 'stock-ledger', 7),
                self::item('warehouses', 'Warehouses', 'folder', null, 'dashboard.view', 'warehouses', 7),
                self::item('stock-transfers', 'Stock Transfers', 'refresh', null, 'dashboard.view', 'stock-transfers', 7),
                self::item('reservations', 'Reservations', 'layers', null, 'dashboard.view', 'stock-reservations', 7),
            ]),
            self::group('accounts', 'Accounts', [
                self::item('ledger-reports', 'Ledger & reports', 'book', 'admin.accounts.workspace', 'accounts.view'),
                self::item('payables', 'Payables', 'invoice', 'admin.accounts.payables', 'accounts.view'),
                self::item('income-expenses', 'Income & expenses', 'banknote', 'admin.accounts.expenses', 'accounts.view'),
            ]),
            self::group('growth', 'Growth', [
                self::item('automations', 'Automations', 'zap', 'admin.automation.index', 'automation.manage', null, 10),
                self::item('sequences', 'Sequences', 'refresh', 'admin.automation.sequences', 'automation.manage', null, 10),
                self::item('whatsapp-templates', 'WhatsApp Templates', 'whatsapp', 'admin.whatsapp.templates', 'integrations.manage', null, 10),
                self::item('marketing', 'Marketing', 'megaphone', 'admin.marketing.index', 'social.manage', 'marketing', 10),
            ]),
            self::group('website-studio', 'Website Studio', [
                self::item('site-overview', 'Site overview', 'layout', null, 'website.edit', null, 10),
                self::item('pages', 'All pages', 'layout', 'admin.website.pages', 'website.edit', 'website', 10),
                self::item('page-builder', 'Page builder', 'panel', 'admin.website.builder', 'website.edit', 'website', 10),
                self::item('navigation', 'Navigation', 'layers', null, 'website.edit', null, 10),
                self::item('sections-templates', 'Sections & templates', 'layers', null, 'website.edit', null, 10),
                self::item('shop-design', 'Shop customization', 'folder', 'admin.commerce.shop', 'commerce.catalog.view'),
                self::item('blog', 'Blog', 'book', 'admin.website.blog', 'website.edit', null, 10),
                self::item('portfolio', 'Portfolio', 'layout', 'admin.website.portfolio', 'website.edit', null, 10),
                self::item('forms', 'Forms', 'file', 'admin.website.forms', 'website.edit', null, 10),
                self::item('media', 'Media', 'folder', 'admin.website.media', 'website.edit'),
                self::item('theme', 'Theme', 'palette', 'admin.website.theme', 'theme.manage', 'website/theme', 10),
                self::item('seo', 'SEO', 'target', 'admin.website.seo.overview', 'website.edit', null, 10),
                self::item('seo-agent', 'SEO agent', 'search', 'admin.website.seo', 'website.edit', null, 10),
                self::item('speed', 'Speed', 'gauge', 'admin.website.speed', 'website.edit', null, 10),
                self::item('site-health', 'Site health', 'health', 'admin.website.health', 'website.edit', null, 10),
                self::item('website-settings', 'Website Settings', 'settings', 'admin.website.settings', 'settings.manage', null, 10),
                self::item('redirects', 'Redirects', 'globe', 'admin.website.redirects', 'website.edit', null, 10),
                self::item('find-replace', 'Find & replace', 'search', 'admin.website.find-replace', 'website.edit', null, 10),
            ]),
            self::group('system', 'System', [
                self::item('settings', 'Settings', 'settings', 'admin.settings', 'settings.manage'),
                self::item('access-team', 'Access & team', 'shield', 'admin.system.access', 'users.manage'),
                self::item('connections', 'Connections', 'link', 'admin.system.connections', 'integrations.manage'),
                self::item('platform', 'Platform', 'activity', 'admin.system.platform', 'system.health'),
                self::item('audit', 'Audit & activity', 'activity', 'admin.system.audit', 'audit.view'),
                self::item('feature-status', 'Feature status', 'layers', 'admin.system.features', 'dashboard.view'),
                // Each person's own (T-056): open to everybody who can open the admin.
                self::item('workspace-preferences', 'Workspace preferences', 'settings', 'admin.system.preferences', 'dashboard.view'),
                self::item('team-onboarding', 'Team onboarding', 'users', 'admin.system.onboarding', 'users.manage'),
            ]),
        ];
    }

    /**
     * The registry in the shape older callers read: a list of titled groups.
     *
     * @return list<array{id: string, title: string, items: list<array<string, mixed>>}>
     */
    public static function groups(): array
    {
        return self::registry();
    }

    /**
     * The sidebar for this person: destinations that exist here, are switched on, and that they may use.
     *
     * @return list<array{id: string, title: string, items: list<array<string, mixed>>}>
     */
    public static function for(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $groups = [];
        foreach (self::registry() as $group) {
            $items = array_values(array_filter(
                $group['items'],
                static fn (array $item): bool => self::state($item) === self::AVAILABLE && self::permitted($item, $user),
            ));

            if ($items !== []) {
                $groups[] = ['id' => $group['id'], 'title' => $group['title'], 'items' => $items];
            }
        }

        return $groups;
    }

    /**
     * The "New" menu of the top bar: the records this person can start from any screen.
     *
     * Every entry is a screen that exists; the rule is the one its own route applies, and an entry of a
     * switched-off module is left out. The letters are the keyboard shortcuts of the admin layout (GLB-016).
     *
     * @return list<array{id: string, label: string, icon: string, url: string, shortcut: string|null}>
     */
    public static function quickCreate(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $entries = [
            ['lead', 'New lead', 'users', 'admin.leads.index', ['tab' => 'list', 'new' => 1], $user->can('create', Lead::class), 'N'],
            ['quotation', 'New quotation', 'quote', 'admin.quotes.create', [], $user->can('quotes.manage'), 'Q'],
            ['estimate', 'New estimate', 'calculator', 'admin.estimations.create', [], $user->can('estimates.view') && $user->can('estimates.manage'), null],
            ['invoice', 'New invoice', 'invoice', 'admin.invoices.create', [], $user->can('invoices.manage'), null],
            ['product', 'New product', 'tag', 'admin.commerce.products.create', [], $user->can('commerce.catalog.view') && $user->can('commerce.catalog.manage'), null],
        ];

        $menu = [];
        foreach ($entries as [$id, $label, $icon, $route, $parameters, $allowed, $shortcut]) {
            if ($allowed && Route::has($route) && Modules::routeIsOpen($route)) {
                $menu[] = ['id' => $id, 'label' => $label, 'icon' => $icon, 'url' => route($route, $parameters), 'shortcut' => $shortcut];
            }
        }

        return $menu;
    }

    /**
     * Where the current request is in the menu, for the top bar: the group and the destination.
     *
     * The match is the sidebar's own (the destination's route, or any route under it). Null on a screen that
     * is no destination of the menu, such as the profile page.
     *
     * @return array{group: string, label: string, url: string, here: bool}|null
     */
    public static function location(?User $user, Request $request): ?array
    {
        foreach (self::for($user) as $group) {
            foreach ($group['items'] as $item) {
                $here = $request->routeIs($item['route']);
                if ($here || $request->routeIs(Str::beforeLast($item['route'], '.index').'.*')) {
                    return ['group' => $group['title'], 'label' => $item['label'], 'url' => route($item['route']), 'here' => $here];
                }
            }
        }

        return null;
    }

    /**
     * Every destination this person is allowed to know about, with where it stands today.
     *
     * Permissions apply here exactly as in the sidebar: somebody who could not open a screen if it
     * existed is not told that it is planned.
     *
     * @return list<array{id: string, title: string, items: list<array<string, mixed>>}>
     */
    public static function status(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $groups = [];
        foreach (self::registry() as $group) {
            $items = [];
            foreach ($group['items'] as $item) {
                if (! self::permitted($item, $user)) {
                    continue;
                }
                $state = self::state($item);
                $items[] = $item + [
                    'state' => $state,
                    'legacy_url' => $state !== self::AVAILABLE && $item['legacy'] !== null ? FurnitureNavigation::url($item['legacy']) : null,
                    'wave_title' => $item['wave'] !== null ? (self::WAVES[$item['wave']] ?? null) : null,
                ];
            }

            if ($items !== []) {
                $groups[] = ['id' => $group['id'], 'title' => $group['title'], 'items' => $items];
            }
        }

        return $groups;
    }

    /**
     * Where a destination stands in this application today.
     *
     * @param  array<string, mixed>  $item
     */
    public static function state(array $item): string
    {
        $route = $item['route'] ?? null;

        if ($route !== null && Route::has($route)) {
            return Modules::routeIsOpen($route) ? self::AVAILABLE : self::OFF;
        }

        return ($item['legacy'] ?? null) !== null ? self::EXISTING : self::PLANNED;
    }

    /**
     * Whether this person may use the destination.
     *
     * The route's own gate decides whenever it has one, so that this page cannot promise a screen and then
     * refuse it: System → Access & team, Connections and Platform each admit one of two permissions, and
     * reading only the registry's single permission hid them from a person who may open them (defect D-045,
     * found on the hosted workspace: an accountant was offered nothing and a settings-only role lost the
     * three hubs).
     *
     * Two entries keep the rule their screens have always had, because their gate is a named ability rather
     * than a list in the route: Approvals follows who can review anything at all, and Estimations is also
     * open to website editors, who maintain the estimator's rates.
     *
     * @param  array<string, mixed>  $item
     */
    private static function permitted(array $item, User $user): bool
    {
        $route = $item['route'] ?? null;

        if ($route === 'admin.approvals') {
            return OperationsSummary::canReview($user);
        }

        if ($route === 'admin.estimations.index') {
            return $user->can('estimates.view') || $user->can('website.edit');
        }

        // The permissions the route itself asks for, read from its middleware: `can:x` (all of them) and
        // `can-any:x,y` (one of them). Null when the route has no such gate.
        $gate = self::routeGate($route);

        if ($gate !== null) {
            foreach ($gate['all'] as $permission) {
                if (! $user->can($permission)) {
                    return false;
                }
            }

            return $gate['any'] === [] || $user->canAny($gate['any']);
        }

        return $user->can($item['permission']);
    }

    /**
     * The permissions a named route gates on, or null when it gates on none.
     *
     * @return array{all: list<string>, any: list<string>}|null
     */
    private static function routeGate(?string $route): ?array
    {
        $router = $route === null ? null : Route::getRoutes()->getByName($route);

        if ($router === null) {
            return null;
        }

        $all = [];
        $any = [];
        foreach ($router->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            if (str_starts_with($middleware, 'can-any:')) {
                $any = array_values(array_filter(array_map(trim(...), explode(',', substr($middleware, 8)))));
            } elseif (str_starts_with($middleware, 'can:')) {
                $permission = trim(explode(',', substr($middleware, 4))[0]);
                if ($permission !== '') {
                    $all[] = $permission;
                }
            }
        }

        if ($all === [] && $any === []) {
            return null;
        }

        return ['all' => array_values(array_unique($all)), 'any' => $any];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{id: string, title: string, items: list<array<string, mixed>>}
     */
    private static function group(string $id, string $title, array $items): array
    {
        return ['id' => $id, 'title' => $title, 'items' => $items];
    }

    /**
     * @return array{id: string, label: string, icon: string, route: string|null, permission: string, legacy: string|null, wave: int|null, phase: null, url: null}
     */
    private static function item(string $id, string $label, string $icon, ?string $route, string $permission, ?string $legacy = null, ?int $wave = null): array
    {
        // `phase` and `url` are always null now. They stay in the shape because screens and tests written
        // for the earlier sidebar read them; a sidebar item is a live link or it is not in the sidebar.
        return ['id' => $id, 'label' => $label, 'icon' => $icon, 'route' => $route, 'permission' => $permission, 'legacy' => $legacy, 'wave' => $wave, 'phase' => null, 'url' => null];
    }
}
