<?php

use App\Http\Controllers\Admin\ClientStatementController;
use App\Http\Controllers\Admin\ConnectorOAuthController;
use App\Http\Controllers\Admin\ContactDataExportController;
use App\Http\Controllers\Admin\ConversationMediaController;
use App\Http\Controllers\Admin\InvoicePdfController;
use App\Http\Controllers\Admin\LeadCsvExportController;
use App\Http\Controllers\Admin\MediaController;
use App\Http\Controllers\Admin\QuoteDraftPreviewController;
use App\Http\Controllers\Admin\QuotePdfController;
use App\Http\Controllers\Admin\SystemUpdateController;
use App\Http\Controllers\Admin\WebsiteBuilderController;
use App\Http\Controllers\Admin\WebsitePagesRedirectController;
use App\Http\Controllers\Admin\WebsiteStudioApiController;
use App\Http\Controllers\Admin\WebsiteStudioController;
use App\Livewire\Admin\Accounts\ChartOfAccounts;
use App\Livewire\Admin\Accounts\JournalsIndex;
use App\Livewire\Admin\Accounts\MethodAccounts;
use App\Livewire\Admin\Accounts\PeriodsIndex;
use App\Livewire\Admin\Accounts\ProfitLoss;
use App\Livewire\Admin\Accounts\TrialBalance;
use App\Livewire\Admin\Automation\SequencesIndex;
use App\Livewire\Admin\Conversations\CannedRepliesIndex;
use App\Livewire\Admin\Conversations\Inbox;
use App\Livewire\Admin\Crm\AssignmentRules;
use App\Livewire\Admin\Crm\CompanyShow;
use App\Livewire\Admin\Crm\ContactShow;
use App\Livewire\Admin\Crm\LeadImport;
use App\Livewire\Admin\Crm\LeadShow;
use App\Livewire\Admin\Hubs\AccountsHub;
use App\Livewire\Admin\Hubs\ContactsHub;
use App\Livewire\Admin\Hubs\DashboardHub;
use App\Livewire\Admin\Hubs\DocumentsHub;
use App\Livewire\Admin\Hubs\InvoicesHub;
use App\Livewire\Admin\Hubs\LeadsHub;
use App\Livewire\Admin\Hubs\LedgerHub;
use App\Livewire\Admin\Hubs\MarketingHub;
use App\Livewire\Admin\Hubs\PayablesHub;
use App\Livewire\Admin\Hubs\PaymentsHub;
use App\Livewire\Admin\Hubs\ProjectCostsHub;
use App\Livewire\Admin\Hubs\QuotationsHub;
use App\Livewire\Admin\Hubs\ReportsHub;
use App\Livewire\Admin\Hubs\SystemAccessHub;
use App\Livewire\Admin\Hubs\SystemConnectionsHub;
use App\Livewire\Admin\Hubs\SystemPlatformHub;
use App\Livewire\Admin\Hubs\TasksHub;
use App\Livewire\Admin\NotificationsPage;
use App\Livewire\Admin\Profile\Security;
use App\Livewire\Admin\Projects\ClientPortalIndex;
use App\Livewire\Admin\Projects\HandoversIndex;
use App\Livewire\Admin\Projects\ProjectBoard;
use App\Livewire\Admin\Projects\ProjectShow;
use App\Livewire\Admin\Projects\ProjectsIndex;
use App\Livewire\Admin\Sales\Approvals;
use App\Livewire\Admin\Sales\EstimateBuilder;
use App\Livewire\Admin\Sales\EstimationsIndex;
use App\Livewire\Admin\Sales\InvoiceCreate;
use App\Livewire\Admin\Sales\InvoiceShow;
use App\Livewire\Admin\Sales\QuoteBuilder;
use App\Livewire\Admin\Sales\QuoteCreate;
use App\Livewire\Admin\System\AlertRulesIndex;
use App\Livewire\Admin\System\AuditLog;
use App\Livewire\Admin\System\AutomationsIndex;
use App\Livewire\Admin\System\ChangeRequests;
use App\Livewire\Admin\System\DataImport;
use App\Livewire\Admin\System\Health;
use App\Livewire\Admin\System\Integrations;
use App\Livewire\Admin\System\RecycleBin;
use App\Livewire\Admin\System\SettingsPage;
use App\Livewire\Admin\System\UsersIndex;
use App\Livewire\Admin\System\WhatsAppTemplates;
use App\Livewire\Admin\Website\FindReplaceIndex;
use App\Livewire\Admin\Website\MediaLibrary;
use App\Livewire\Admin\Website\PagesIndex;
use App\Livewire\Admin\Website\RedirectsIndex;
use App\Livewire\Admin\Website\SeoAgentIndex;
use App\Livewire\Admin\Website\SeoOverviewIndex;
use App\Livewire\Admin\Website\SiteHealthIndex;
use App\Livewire\Admin\Website\SpeedIndex;
use App\Livewire\Admin\Website\ThemeBuilder;
use App\Livewire\Admin\Website\WebsiteSettings;
use Illuminate\Support\Facades\Route;

/*
| Admin routes (prefix/domain and auth middleware are applied in routes/web.php).
| Navigation groups follow docs/01-MASTER-PLAN.md §4; screens arrive phase by phase.
*/

// P10.1 workflow hubs (docs/27): one page per workflow, tabs inside; old URLs redirect to the tab.
Route::livewire('/', DashboardHub::class)->name('dashboard')->middleware('can:dashboard.view');
Route::livewire('/notifications', NotificationsPage::class)->name('notifications')->middleware('can:dashboard.view');
Route::get('/my-day', fn () => redirect()->route('admin.dashboard', ['tab' => 'my-day'], 301))->name('my-day')->middleware('can:dashboard.view');

// CRM (P1): Leads (list · pipeline · tracking), Contacts (people · companies), Tasks & Visits
Route::livewire('/leads', LeadsHub::class)->name('leads.index')->middleware('can:leads.view');
Route::livewire('/leads/import', LeadImport::class)->name('leads.import')->middleware('can:system.import');
Route::get('/leads/export.csv', LeadCsvExportController::class)->name('leads.export')->middleware('can:system.import');
Route::livewire('/leads/{lead}', LeadShow::class)->name('leads.show')->middleware('can:leads.view');
Route::get('/pipeline', fn () => redirect()->route('admin.leads.index', ['tab' => 'pipeline'], 301))->name('pipeline')->middleware('can:leads.view');
Route::livewire('/contacts', ContactsHub::class)->name('contacts.index')->middleware('can:contacts.view');
// Slice J: the reference product's contact views as first-class URLs, answering with the right tab.
Route::get('/clients', fn () => redirect()->route('admin.contacts.index', ['tab' => 'clients'], 301))->name('crm.clients')->middleware('can:contacts.view');
Route::get('/customers', fn () => redirect()->route('admin.contacts.index', ['tab' => 'customers'], 301))->name('crm.customers')->middleware('can:contacts.view');
Route::get('/visitors', fn () => redirect()->route('admin.contacts.index', ['tab' => 'visitors'], 301))->name('crm.visitors')->middleware('can:contacts.view');
Route::livewire('/contacts/{contact}', ContactShow::class)->name('contacts.show')->middleware('can:contacts.view');
Route::livewire('/companies/{company}', CompanyShow::class)->name('companies.show')->middleware('can:contacts.view');
Route::get('/companies', fn () => redirect()->route('admin.contacts.index', ['tab' => 'companies'], 301))->name('companies.index')->middleware('can:contacts.view');
Route::livewire('/tasks', TasksHub::class)->name('tasks.index')->middleware('can:leads.view');
Route::get('/site-visits', fn () => redirect()->route('admin.tasks.index', ['tab' => 'visits'], 301))->name('visits.index')->middleware('can:leads.view');

// Sales (P2): Estimations → Quotations (all · tracking · templates) → Invoices (all · tracking) → Payments (all · dues · receivables) → Documents
Route::livewire('/quotes', QuotationsHub::class)->name('quotes.index')->middleware('can:quotes.view');
// Slice K: the reference product's proposal/pipeline URLs answer with the right quotation tab.
Route::get('/proposals', fn () => redirect()->route('admin.quotes.index', ['tab' => 'proposals'], 301))->name('sales.proposals')->middleware('can:quotes.view');
Route::get('/sales-pipeline', fn () => redirect()->route('admin.quotes.index', ['tab' => 'pipeline'], 301))->name('sales.pipeline')->middleware('can:quotes.view');
Route::livewire('/quotes/new', QuoteCreate::class)->name('quotes.create')->middleware('can:quotes.manage');
Route::livewire('/quotes/{quote}', QuoteBuilder::class)->name('quotes.show')->middleware('can:quotes.view')->whereNumber('quote');
Route::post('/quotes/{quote}/preview', QuoteDraftPreviewController::class)->name('quotes.preview')->middleware(['can:quotes.view', 'throttle:120,1'])->whereNumber('quote');
Route::get('/quotes/{quote}/pdf', QuotePdfController::class)->name('quotes.pdf')->middleware('can:quotes.view')->whereNumber('quote');
Route::livewire('/invoices', InvoicesHub::class)->name('invoices.index')->middleware('can:invoices.view');
Route::livewire('/invoices/new', InvoiceCreate::class)->name('invoices.create')->middleware('can:invoices.manage');
Route::livewire('/invoices/{invoice}', InvoiceShow::class)->name('invoices.show')->middleware('can:invoices.view')->whereNumber('invoice');
Route::get('/invoices/{invoice}/pdf', InvoicePdfController::class)->name('invoices.pdf')->middleware('can:invoices.view')->whereNumber('invoice');
Route::livewire('/payments', PaymentsHub::class)->name('payments.index')->middleware('can:invoices.view');
Route::livewire('/estimations', EstimationsIndex::class)->name('estimations.index')->middleware('can:estimations.access');
Route::livewire('/estimations/new', EstimateBuilder::class)->name('estimations.create')->middleware('can:estimates.view');
Route::livewire('/estimations/{estimate}', EstimateBuilder::class)->name('estimations.show')->middleware('can:estimates.view')->whereNumber('estimate');
Route::get('/sales/dues', fn () => redirect()->route('admin.payments.index', ['tab' => 'dues'], 301))->name('sales.dues')->middleware('can:invoices.view');
Route::get('/sales/tracking/quotes', fn () => redirect()->route('admin.quotes.index', ['tab' => 'tracking'], 301))->name('sales.tracking.quotes')->middleware('can:quotes.view');
Route::get('/sales/tracking/invoices', fn () => redirect()->route('admin.invoices.index', ['tab' => 'tracking'], 301))->name('sales.tracking.invoices')->middleware('can:invoices.view');
Route::get('/clients/{contact}/statement.pdf', ClientStatementController::class)->name('contacts.statement')->middleware('can:invoices.view')->whereNumber('contact');
Route::get('/contacts/{contact}/data.json', ContactDataExportController::class)->name('contacts.data')->middleware('can:leads.view')->whereNumber('contact');
Route::livewire('/accounts', AccountsHub::class)->name('accounts.workspace')->middleware('can:accounts.view');
Route::livewire('/accounts/chart', ChartOfAccounts::class)->name('accounts.chart')->middleware('can:accounts.view');
Route::livewire('/accounts/journals', JournalsIndex::class)->name('accounts.journals')->middleware('can:accounts.view');
Route::livewire('/accounts/periods', PeriodsIndex::class)->name('accounts.periods')->middleware('can:accounts.view');
Route::livewire('/accounts/trial-balance', TrialBalance::class)->name('accounts.trial')->middleware('can:accounts.view');
Route::livewire('/accounts/methods', MethodAccounts::class)->name('accounts.methods')->middleware('can:accounts.view');
Route::livewire('/accounts/payables', PayablesHub::class)->name('accounts.payables')->middleware('can:accounts.view');
Route::livewire('/accounts/expenses', LedgerHub::class)->name('accounts.expenses')->middleware('can:accounts.view');
Route::livewire('/accounts/profit-loss', ProfitLoss::class)->name('accounts.profit')->middleware('can:accounts.view');
Route::livewire('/approvals', Approvals::class)->name('approvals')->middleware('can-any:quotes.approve,payments.verify,social.manage,system.change_requests');
Route::livewire('/sales/documents', DocumentsHub::class)->name('sales.documents')->middleware('can:quotes.view');
Route::get('/quote-templates', fn () => redirect()->route('admin.quotes.index', ['tab' => 'templates'], 301))->name('quote-templates')->middleware('can:quotes.view');
Route::get('/rate-book', fn () => redirect()->route('admin.sales.documents', ['tab' => 'rate-book'], 301))->name('rate-book')->middleware('can:quotes.view');
Route::get('/description-library', fn () => redirect()->route('admin.sales.documents', ['tab' => 'descriptions'], 301))->name('description-library')->middleware('can:quotes.view');
Route::get('/document-design', fn () => redirect()->route('admin.sales.documents', ['tab' => 'design'], 301))->name('document-design')->middleware('can:quotes.view');

Route::livewire('/projects/costs', ProjectCostsHub::class)->name('projects.costs')->middleware('can:accounts.view');

// Projects (P4, PRJ-001…PRJ-005)
Route::livewire('/projects', ProjectsIndex::class)->name('projects.index')->middleware('can:projects.view');
Route::livewire('/projects/board', ProjectBoard::class)->name('projects.board')->middleware('can:projects.view');
// The two screens the sidebar used to advertise as "P4b" while the feature was live: the client's
// signed door on every job, and the handover list (deep QA 21 Oct, slice B).
Route::livewire('/projects/portal', ClientPortalIndex::class)->name('projects.portal')->middleware('can:projects.view');
Route::livewire('/projects/handovers', HandoversIndex::class)->name('projects.handovers')->middleware('can:projects.view');
Route::livewire('/projects/{project}', ProjectShow::class)->name('projects.show')->middleware('can:projects.view')->whereNumber('project');

// Conversations (P4, CNV-001/CNV-002, WAP-001…WAP-004): one inbox for WhatsApp and the website chat widget.
Route::livewire('/inbox', Inbox::class)->name('inbox')->middleware('can:inbox.view');
Route::get('/conversations/media/{message}', ConversationMediaController::class)->name('conversations.media')->middleware('can:inbox.view');

// Private attachments (GLB-006)
Route::get('/media/{media}/{conversion?}', MediaController::class)->name('media.show')->whereIn('conversion', ['thumb']);

// Website (phase P6, docs/09): the public website is content, edited here.
// Slice M: Marketing becomes a hub — planner + attribution; the old planner URL keeps working.
Route::livewire('/marketing', MarketingHub::class)->name('marketing.index')->middleware('can:social.manage');
Route::get('/marketing/social', fn () => redirect()->route('admin.marketing.index', ['tab' => 'planner'], 301))->name('marketing.social')->middleware('can:social.manage');

Route::livewire('/website/pages', PagesIndex::class)->name('website.pages')->middleware('can:website.edit');
Route::livewire('/website/find-replace', FindReplaceIndex::class)->name('website.find-replace')->middleware('can:website.edit');

/*
 * The ported v2.1 admin screens (docs/audit/16.8). Full document: they bring their own stylesheet and
 * layout, the same exception the builder has. One URL per screen, and the screen has to be switched on
 * in WebsiteStudioController::SCREENS before it resolves.
 */
Route::get('/website/studio/{screen}', [WebsiteStudioController::class, 'show'])
    ->name('website.studio')->middleware('can:website.edit');
Route::post('/website/studio/api', WebsiteStudioApiController::class)
    ->name('website.studio.api')->middleware('can:website.edit');
Route::post('/website/studio/builder-api', WebsiteStudioApiController::class)
    ->name('website.studio.builder-api')->middleware('can:website.edit');

Route::get('/website/estimator', fn () => redirect()->route('admin.estimations.index', ['tab' => 'rates']))->name('website.estimator')->middleware('can:website.edit');

// The screens the OS navigation links to by name, instead of a route with a parameter.
foreach (['blog' => 'website.blog', 'portfolio' => 'website.portfolio', 'forms' => 'website.forms'] as $screen => $name) {
    Route::get('/website/'.$screen, [WebsiteStudioController::class, 'show'])
        ->defaults('screen', $screen)->name($name)->middleware('can:website.edit');
}

// A page is a file and the builder is its editor: the old editor URLs open the builder on that file.
Route::get('/website/pages/new', WebsitePagesRedirectController::class)->name('website.pages.create')->middleware('can:website.edit');
Route::get('/website/pages/{page}', WebsitePagesRedirectController::class)->name('website.pages.edit')->middleware('can:website.edit');

// Visual Page Builder (v2.1 front-end, OS back-end): edits the site's HTML files, opened with ?path=… .
Route::get('/website/builder', [WebsiteBuilderController::class, 'show'])->name('website.builder')->middleware('can:website.edit');
Route::post('/website/builder/api', [WebsiteBuilderController::class, 'api'])->name('website.builder.api')->middleware('can:website.edit');
Route::livewire('/website/seo-overview', SeoOverviewIndex::class)->name('website.seo.overview')->middleware('can:website.edit');
Route::livewire('/website/health', SiteHealthIndex::class)->name('website.health')->middleware('can:website.edit');
Route::livewire('/website/media', MediaLibrary::class)->name('website.media')->middleware('can:website.edit');
Route::livewire('/website/redirects', RedirectsIndex::class)->name('website.redirects')->middleware('can:website.edit');
Route::livewire('/website/seo', SeoAgentIndex::class)->name('website.seo')->middleware('can:website.edit');
Route::livewire('/website/speed', SpeedIndex::class)->name('website.speed')->middleware('can:website.edit');
Route::livewire('/website/settings', WebsiteSettings::class)->name('website.settings')->middleware('can:settings.manage');
Route::livewire('/website/theme', ThemeBuilder::class)->name('website.theme')->middleware('can:theme.manage');

// Profile
Route::livewire('/profile/security', Security::class)->name('profile.security');

// Reports (P3): the group folded into Overview — one page, two views (P10.1)
Route::livewire('/reports', ReportsHub::class)->name('reports.index')->middleware('can:reports.view');
Route::get('/reports/sales-funnel', fn () => redirect()->route('admin.reports.index', ['tab' => 'funnel'], 301))->name('reports.funnel')->middleware('can:reports.view');
Route::get('/reports/revenue-collections', fn () => redirect()->route('admin.reports.index', ['tab' => 'revenue'], 301))->name('reports.revenue')->middleware('can:reports.view');
Route::get('/receivables', fn () => redirect()->route('admin.payments.index', ['tab' => 'receivables'], 301))->name('reports.receivables')->middleware('can:invoices.view');

// System — four pages with their buttons inside (deep QA 21 Oct, slice B). The individual routes
// below stay: bookmarks, Telegram buttons and notifications still point at them.
Route::livewire('/system/access', SystemAccessHub::class)->name('system.access')->middleware('can-any:users.manage,settings.manage');
Route::livewire('/system/connections', SystemConnectionsHub::class)->name('system.connections')->middleware('can-any:integrations.manage,system.import');
Route::livewire('/system/platform', SystemPlatformHub::class)->name('system.platform')->middleware('can-any:system.health,audit.view,system.change_requests,settings.manage,leads.delete');
// The chat bot has no screen of its own: its knobs are a tab of Settings. The sidebar entry opens it.
Route::get('/chat-bot', fn () => redirect()->route('admin.settings', ['tab' => 'bot']))->name('chat-bot')->middleware('can:settings.manage');
Route::livewire('/settings', SettingsPage::class)->name('settings')->middleware('can:settings.manage');
Route::livewire('/settings/canned-replies', CannedRepliesIndex::class)->name('canned-replies')->middleware('can:settings.manage');
Route::livewire('/settings/lead-assignment', AssignmentRules::class)->name('assignment-rules')->middleware('can:settings.manage');
Route::livewire('/users', UsersIndex::class)->name('users.index')->middleware('can:users.manage');
Route::livewire('/system/health', Health::class)->name('system.health')->middleware('can:system.health');

Route::livewire('/automation', AutomationsIndex::class)->name('automation.index')->middleware('can:automation.manage');
Route::livewire('/automation/sequences', SequencesIndex::class)->name('automation.sequences')->middleware('can:automation.manage');
Route::livewire('/system/change-requests', ChangeRequests::class)->name('system.change-requests')->middleware('can:system.change_requests');
Route::livewire('/system/alert-rules', AlertRulesIndex::class)->name('system.alert-rules')->middleware('can:settings.manage');
Route::livewire('/system/audit', AuditLog::class)->name('system.audit')->middleware('can:audit.view');
// Database update after a deploy (D-58): reachable while migrations are pending and before 2FA setup.
Route::get('/system/updates', [SystemUpdateController::class, 'show'])->name('system.updates')
    ->middleware('can:system.health')->withoutMiddleware(['db-current', 'two-factor']);
Route::post('/system/updates', [SystemUpdateController::class, 'apply'])->name('system.updates.apply')
    ->middleware('can:system.health')->withoutMiddleware(['db-current', 'two-factor']);
Route::livewire('/recycle-bin', RecycleBin::class)->name('recycle-bin')->middleware('can:leads.delete');
Route::livewire('/integrations', Integrations::class)->name('integrations')->middleware('can:integrations.manage');
// Real connector authorisation: the browser round trip for OAuth providers (Google Workspace).
Route::get('/integrations/oauth/{provider}/start', [ConnectorOAuthController::class, 'start'])->name('integrations.oauth.start')->middleware('can:integrations.manage');
Route::get('/integrations/oauth/{provider}/callback', [ConnectorOAuthController::class, 'callback'])->name('integrations.oauth.callback')->middleware('can:integrations.manage');
Route::livewire('/integrations/whatsapp-templates', WhatsAppTemplates::class)->name('whatsapp.templates')->middleware('can:integrations.manage');
Route::livewire('/data-import', DataImport::class)->name('data-import')->middleware('can:system.import');
