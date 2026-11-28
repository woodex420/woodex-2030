<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Accounts\Models\Bill;
use App\Domain\Accounts\Models\BillPayment;
use App\Domain\Accounts\Models\Expense;
use App\Domain\Accounts\Models\RecurringExpense;
use App\Domain\Accounts\Models\Vendor;
use App\Domain\Automation\Listeners\EnrollTriggeredLeads;
use App\Domain\Commerce\Models\Category;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\Series;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Policies\ConversationPolicy;
use App\Domain\Crm\Events\LeadCaptured;
use App\Domain\Crm\Events\LeadStageChanged;
use App\Domain\Crm\Listeners\AskForMissingNumber;
use App\Domain\Crm\Listeners\SendNewLeadAlerts;
use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\SiteVisit;
use App\Domain\Crm\Models\Task;
use App\Domain\Crm\Policies\CompanyPolicy;
use App\Domain\Crm\Policies\ContactPolicy;
use App\Domain\Crm\Policies\LeadPolicy;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectUpdate;
use App\Domain\Projects\Policies\ProjectPolicy;
use App\Domain\Sales\Events\InvoicePaid;
use App\Domain\Sales\Events\QuoteSent;
use App\Domain\Sales\Listeners\RecordEstimatorEstimate;
use App\Domain\Sales\Models\BankAccount;
use App\Domain\Sales\Models\DescriptionTemplate;
use App\Domain\Sales\Models\DocumentDesign;
use App\Domain\Sales\Models\Estimate;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\RecurringInvoice;
use App\Domain\Sales\Policies\InvoicePolicy;
use App\Domain\Sales\Policies\PaymentPolicy;
use App\Domain\Sales\Policies\QuotePolicy;
use App\Domain\System\Actions\RecordSettingsChange;
use App\Domain\System\Models\ChangeRequest;
use App\Domain\Website\Models\Media;
use App\Http\Middleware\EnsureUserIsActive;
use App\Integrations\Google\Contracts\GoogleGateway;
use App\Integrations\Google\GoogleClient;
use App\Integrations\Telegram\Clients\TelegramBotApi;
use App\Integrations\Telegram\Contracts\TelegramGateway;
use App\Integrations\Webhooks\WebhookEventListener;
use App\Integrations\WhatsApp\Clients\WhatsAppCloudApi;
use App\Integrations\WhatsApp\Contracts\WhatsAppGateway;
use App\Integrations\WhatsApp\WhatsAppCredentials;
use App\Livewire\Support\TellsTheBrowserOfComplaints;
use App\Models\User;
use App\Support\BridgedVisitor;
use App\Support\DebugGuard;
use App\Support\WorkspacePreferences;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;
use Spatie\LaravelSettings\Events\SavingSettings;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(GoogleGateway::class, GoogleClient::class);

        $this->app->singleton(TelegramGateway::class, fn (): TelegramGateway => new TelegramBotApi(
            config('services.telegram.bot_token'),
            (string) config('services.telegram.api_base', 'https://api.telegram.org'),
        ));

        $this->app->singleton(WhatsAppCredentials::class, fn (): WhatsAppCredentials => new WhatsAppCredentials);
        $this->app->singleton(WhatsAppGateway::class, fn (): WhatsAppGateway => new WhatsAppCloudApi(
            $this->app->make(WhatsAppCredentials::class),
        ));
    }

    public function boot(): void
    {
        DebugGuard::apply($this->app);

        RateLimiter::for('telegram-webhooks', function (Request $request): array {
            $sender = $request->input('message.from.id');
            $key = hash('sha256', is_scalar($sender) ? (string) $sender : 'unknown');
            $limits = [Limit::perMinute(300)->by('telegram-global'), Limit::perMinute(30)->by('telegram-actor:'.$key)];
            $text = $request->input('message.text');
            if (is_string($text) && preg_match('/^\/(start|link)(?:@\w+)?(?:\s|$)/i', trim($text))) {
                $limits[] = Limit::perMinute(5)->by('telegram-link:'.$key);
            }

            return $limits;
        });

        RateLimiter::for('google-oauth', fn (Request $request): Limit => Limit::perMinute(20)->by((string) $request->ip()));
        RateLimiter::for('google-start', fn (Request $request): Limit => Limit::perMinute(5)->by((string) $request->ip()));

        Model::preventLazyLoading(! $this->app->isProduction());

        // Links and assets always use https when the site address does, also when a CDN talks plain http to the
        // server (Hostinger CDN): otherwise the browser blocks the CSS as mixed content.
        if ($this->app->isProduction() && str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Short, stable type names in polymorphic columns (docs/04 §1).
        Relation::enforceMorphMap([
            'commerce_category' => Category::class,
            'commerce_series' => Series::class,
            'commerce_product' => Product::class,
            'website_media' => Media::class,
            'user' => User::class,
            'company' => Company::class,
            'contact' => Contact::class,
            'lead' => Lead::class,
            'activity' => Activity::class,
            'task' => Task::class,
            'site_visit' => SiteVisit::class,
            'quote' => Quote::class,
            'description_template' => DescriptionTemplate::class,
            'document_design' => DocumentDesign::class,
            'invoice' => Invoice::class,
            'recurring_invoice' => RecurringInvoice::class,
            'bank_account' => BankAccount::class,
            'payment' => Payment::class,
            'estimate' => Estimate::class,
            'project' => Project::class,
            'project_update' => ProjectUpdate::class,
            'change_request' => ChangeRequest::class,
            'vendor' => Vendor::class,
            'bill' => Bill::class,
            'bill_payment' => BillPayment::class,
            'expense' => Expense::class,
            'recurring_expense' => RecurringExpense::class,
        ]);

        Gate::define('estimations.access', fn (User $user): bool => $user->is_active && ($user->can('estimates.view') || $user->can('website.edit')));

        Gate::policy(Lead::class, LeadPolicy::class);
        Gate::policy(Contact::class, ContactPolicy::class);
        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(Quote::class, QuotePolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Conversation::class, ConversationPolicy::class);

        // The owner can do everything (PRD §7).
        Gate::before(fn (User $user): ?bool => $user->hasRole('owner') ? true : null);

        Password::defaults(fn () => Password::min(10)->letters()->numbers());

        // Counted by the visitor: by the sender's own address, or by the one the shop's server names when it
        // carried the enquiry here and holds the bridge key (App\Support\BridgedVisitor, master list T-191).
        RateLimiter::for('public-leads', fn (Request $request): array => [
            Limit::perMinute(5)->by('leads-min:'.BridgedVisitor::address($request)),
            Limit::perDay(50)->by('leads-day:'.BridgedVisitor::address($request)),
        ]);

        // What the shop reads of /api/v1: 120 a minute for one visitor, over all those addresses together, as it
        // was. Counted by the visitor for the same reason as above: every read of every visitor reaches this
        // application from the shop's one server, and counted by that server's address the whole shop had one
        // visitor's allowance (defect D-082: about fourteen open pages use it up, and the shop then says that
        // its catalogue is not available). Whoever does not hold the bridge key is counted by the address he
        // really has.
        RateLimiter::for('v1-read', fn (Request $request): Limit => Limit::perMinute(120)->by('v1-read:'.BridgedVisitor::address($request)));

        // The original pages ask for the form's configuration on every page view and poll the chat every few
        // seconds, so those two answers need a room of their own: the lead limiter above is for writing a lead.
        RateLimiter::for('public-site', fn (Request $request): array => [
            Limit::perMinute(120)->by('site-min:'.$request->ip()),
            Limit::perDay(20_000)->by('site-day:'.$request->ip()),
        ]);

        // The web chat widget (P8.5): a person types a few messages a minute, the poll runs every
        // five seconds while the panel is open. Both keyed to the address, not the token.
        RateLimiter::for('chat-write', fn (Request $request): array => [
            Limit::perMinute(20)->by('chat-write:'.$request->ip()),
            Limit::perDay(500)->by('chat-write-day:'.$request->ip()),
        ]);

        RateLimiter::for('chat-poll', fn (Request $request): Limit => Limit::perMinute(90)->by('chat-poll:'.$request->ip()));

        RateLimiter::for('webhooks', fn (Request $request): Limit => Limit::perMinute(120)->by('webhook:'.$request->ip()));

        // Re-applied on every Livewire update request, not only on the first page load.
        Livewire::addPersistentMiddleware([EnsureUserIsActive::class]);

        // An answer that carries a complaint says so, and the browser keeps the mark "not saved yet" (T-043).
        TellsTheBrowserOfComplaints::listen();

        // Every saved setting leaves a row in the list of changes (T-067).
        Event::listen(SavingSettings::class, RecordSettingsChange::class);

        // What every log line of a request says besides its number (App\Support\RequestNumber, T-062): which
        // route answered — its pattern, never the address itself, which can carry a token — and who asked, by
        // number. Both become known only after the request has begun.
        Event::listen(RouteMatched::class, function (RouteMatched $event): void {
            if (! $event->route->isFallback) {
                Context::add(array_filter(['route' => $event->route->getName(), 'path' => $event->route->uri()]));
            }
        });
        Event::listen([Authenticated::class, Login::class], function (Authenticated|Login $event): void {
            Context::add('user_id', $event->user->getAuthIdentifier());
        });

        Event::listen(LeadCaptured::class, SendNewLeadAlerts::class);
        // Slice C: an estimator enquiry arrives with the three facts the rate book needs, so its draft
        // estimate is written now instead of waiting for somebody to open the lead.
        Event::listen(LeadCaptured::class, RecordEstimatorEstimate::class);
        Event::listen(LeadCaptured::class, AskForMissingNumber::class);

        // AUT-001: the sequences' event-driven triggers.
        Event::listen(LeadCaptured::class, [EnrollTriggeredLeads::class, 'handleLeadCaptured']);
        Event::listen(LeadStageChanged::class, [EnrollTriggeredLeads::class, 'handleStageChanged']);

        // INT: the owner's own apps (Zapier, Make, any HTTPS endpoint) hear the same moments.
        Event::listen(LeadCaptured::class, [WebhookEventListener::class, 'handleLeadCaptured']);
        Event::listen(QuoteSent::class, [WebhookEventListener::class, 'handleQuoteSent']);
        Event::listen(InvoicePaid::class, [WebhookEventListener::class, 'handleInvoicePaid']);

        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                // A first sign-in is led to the welcome (T-056). The mark lives in the session until that page
                // is shown, so a role that must switch on two-factor first is still led there afterwards.
                if ($event->user->last_login_at === null && request()->hasSession()) {
                    request()->session()->put(WorkspacePreferences::FIRST_SIGN_IN, true);
                }
                $event->user->forceFill([
                    'last_login_at' => now(),
                    'last_login_ip' => request()->ip(),
                ])->saveQuietly();
            }
        });
    }
}
