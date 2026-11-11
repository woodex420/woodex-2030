<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\System\Control\OperationsSummary;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Admin sidebar, grouped by job to be done (docs/01-MASTER-PLAN.md §4).
 * Screens that arrive in later phases are shown disabled with their phase badge,
 * so the team can see the whole roadmap from day one.
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

    /**
     * @return list<array{title: string|null, items: list<array{label: string, icon: string, route: string|null, permission: string|null, phase: string|null}>}>
     */
    public static function groups(): array
    {
        return [
            // P10.1 (docs/27): one page per workflow, tabs inside — 49 live entries became 37.
            self::group('Overview', [
                self::item('Dashboard', 'dashboard', 'admin.dashboard', 'dashboard.view'),
                self::item('Approvals', 'check-circle', 'admin.approvals', 'quotes.approve'),
                self::item('Reports', 'chart', 'admin.reports.index', 'reports.view'),
            ]),
            self::group('CRM', [
                self::item('Leads', 'users', 'admin.leads.index', 'leads.view'),
                self::item('Contacts', 'contact', 'admin.contacts.index', 'contacts.view'),
                self::item('Tasks & Visits', 'tasks', 'admin.tasks.index', 'leads.view'),
            ]),
            self::group('Sales', [
                self::item('Estimations', 'calculator', 'admin.estimations.index', 'estimates.view'),
                self::item('Quotations', 'quote', 'admin.quotes.index', 'quotes.view'),
                self::item('Invoices', 'invoice', 'admin.invoices.index', 'invoices.view'),
                self::item('Payments', 'wallet', 'admin.payments.index', 'invoices.view'),
                self::item('Documents', 'book', 'admin.sales.documents', 'quotes.view'),
            ]),
            self::group('Accounts', [
                self::item('Ledger & reports', 'book', 'admin.accounts.workspace', 'accounts.view'),
                self::item('Payables', 'invoice', 'admin.accounts.payables', 'accounts.view'),
                self::item('Income & expenses', 'banknote', 'admin.accounts.expenses', 'accounts.view'),
            ]),
            self::group('Projects', [
                self::item('Projects', 'hammer', 'admin.projects.index', 'projects.view'),
                self::item('Board', 'kanban', 'admin.projects.board', 'projects.view'),
                self::item('Vendors & costs', 'wallet', 'admin.projects.costs', 'accounts.view'),
                self::item('Client portal', 'link', 'admin.projects.portal', 'projects.view'),
                self::item('Handovers', 'check-circle', 'admin.projects.handovers', 'projects.view'),
            ]),
            self::group('Inbox', [
                self::item('Conversations', 'inbox', 'admin.inbox', 'inbox.view'),
                // The assistant is live; its switches are a Settings tab. No more "P4" badge over shipped code.
                self::item('Chat Bot', 'bot', 'admin.chat-bot', 'settings.manage'),
            ]),
            self::group('Automation', [
                self::item('Automations', 'zap', 'admin.automation.index', 'automation.manage'),
                self::item('Sequences', 'refresh', 'admin.automation.sequences', 'automation.manage'),
                self::item('WhatsApp Templates', 'whatsapp', 'admin.whatsapp.templates', 'integrations.manage'),
            ]),
            self::group('Marketing', [
                self::item('Marketing', 'megaphone', 'admin.marketing.index', 'social.manage'),
            ]),
            // The Website section is the v2.1 front, grouped the way it is worked on (docs/16 §16.8):
            // build a page, fill it with content, then tune the site. The Content library left the nav on
            // 20 Oct and the screen itself was retired on 21 Oct (step 22): it held rows no page file read.
            self::group('Pages & builder', [
                self::item('All pages', 'layout', 'admin.website.pages', 'website.edit'),
                self::item('Page builder', 'panel', 'admin.website.builder', 'website.edit'),
                // The site-wide edit the files made necessary: the phone number is in all 142 pages
                // (docs/audit/21 §5.1). It sits beside the builder because it changes the same files.
                self::item('Find & replace', 'search', 'admin.website.find-replace', 'website.edit'),
                self::item('Redirects', 'globe', 'admin.website.redirects', 'website.edit'),
            ]),
            self::group('Content', [
                self::item('Blog', 'book', 'admin.website.blog', 'website.edit'),
                self::item('Portfolio', 'layout', 'admin.website.portfolio', 'website.edit'),
                // The six screens still to port; listed now so the section matches the target, disabled
                // until their actions are switched on in WebsiteStudioController::SCREENS.
                self::item('Forms', 'file', 'admin.website.forms', 'website.edit'),
            ]),
            // The seven existing tools share one Website heading; routes and permissions stay unchanged.
            self::group('Website', [
                self::item('Media', 'folder', 'admin.website.media', 'website.edit'),
                self::item('SEO', 'target', 'admin.website.seo.overview', 'website.edit'),
                self::item('SEO agent', 'search', 'admin.website.seo', 'website.edit'),
                self::item('Speed', 'gauge', 'admin.website.speed', 'website.edit'),
                self::item('Site health', 'health', 'admin.website.health', 'website.edit'),
                self::item('Theme', 'palette', 'admin.website.theme', 'theme.manage'),
                self::item('Website Settings', 'settings', 'admin.website.settings', 'settings.manage'),
            ]),
            // 11 entries became 4 pages with their buttons inside (deep QA 21 Oct, slice B). Every
            // original route still answers; the hub is the front door, the route is the bookmark.
            self::group('System', [
                self::item('Settings', 'settings', 'admin.settings', 'settings.manage'),
                self::item('Access & team', 'shield', 'admin.system.access', 'users.manage'),
                self::item('Connections', 'link', 'admin.system.connections', 'integrations.manage'),
                self::item('Platform', 'activity', 'admin.system.platform', 'system.health'),
            ]),
        ];
    }

    /**
     * Groups filtered for a user: live items need their permission; planned items are always listed.
     *
     * @return list<array{title: string|null, items: list<array{label: string, icon: string, route: string|null, permission: string|null, phase: string|null}>}>
     */
    public static function for(?User $user): array
    {
        $groups = [];

        foreach (self::groups() as $group) {
            $items = array_values(array_filter($group['items'], static function (array $item) use ($user): bool {
                if ($item['route'] === null) {
                    return true;
                }

                if (! Route::has($item['route'])) {
                    return false;
                }

                if ($item['route'] === 'admin.approvals') {
                    return $user !== null && OperationsSummary::canReview($user);
                }

                return ($item['route'] === 'admin.estimations.index' && ($user?->can('website.edit') ?? false)) || $item['permission'] === null || ($user?->can($item['permission']) ?? false);
            }));

            if ($items !== []) {
                $groups[] = ['title' => $group['title'], 'items' => $items];
            }
        }

        return $groups;
    }

    /**
     * @param  list<array{label: string, icon: string, route: string|null, permission: string|null, phase: string|null}>  $items
     * @return array{title: string|null, items: list<array{label: string, icon: string, route: string|null, permission: string|null, phase: string|null}>}
     */
    /**
     * A titled group collapses; a group with no title is a flat run of items.
     *
     * @param  list<array{label: string, icon: string, route: string|null, permission: string|null, phase: string|null}>  $items
     * @return array{title: string|null, items: list<array{label: string, icon: string, route: string|null, permission: string|null, phase: string|null}>}
     */
    private static function group(?string $title, array $items): array
    {
        return ['title' => $title, 'items' => $items];
    }

    /**
     * @return array{label: string, icon: string, route: string|null, permission: string|null, phase: string|null}
     */
    private static function item(string $label, string $icon, ?string $route, ?string $permission, ?string $phase = null): array
    {
        return ['label' => $label, 'icon' => $icon, 'route' => $route, 'permission' => $permission, 'phase' => $phase];
    }
}
