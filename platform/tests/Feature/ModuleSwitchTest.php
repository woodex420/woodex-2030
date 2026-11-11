<?php

declare(strict_types=1);

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Services\QuoteEditor;
use App\Livewire\Admin\Sales\QuoteBuilder;
use App\Support\AdminNavigation;
use App\Support\Modules;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| Woodex Furniture ships with the optional modules switched off (config/modules.php). phpunit.xml switches them
| all on so the suite inherited from WOODEX OS keeps exercising their code; these tests pin the shipped default.
*/

function useShippedModules(): void
{
    config(['modules.enabled' => 'lead_api']);
}

it('ships with only the enquiry endpoint switched on', function () {
    $saved = [$_ENV['WOODEX_MODULES'] ?? null, $_SERVER['WOODEX_MODULES'] ?? null, getenv('WOODEX_MODULES')];
    unset($_ENV['WOODEX_MODULES'], $_SERVER['WOODEX_MODULES']);
    putenv('WOODEX_MODULES');

    try {
        expect((require config_path('modules.php'))['enabled'])->toBe('lead_api');
    } finally {
        if ($saved[0] !== null) {
            $_ENV['WOODEX_MODULES'] = $saved[0];
        }
        if ($saved[1] !== null) {
            $_SERVER['WOODEX_MODULES'] = $saved[1];
        }
        if ($saved[2] !== false) {
            putenv('WOODEX_MODULES='.$saved[2]);
        }
    }
});

it('reads the module list from configuration and never opens an unknown key', function () {
    config(['modules.enabled' => '*']);
    expect(Modules::enabledKeys())->toBe(Modules::available());

    config(['modules.enabled' => ' inbox , website,no_such_module ']);
    expect(Modules::enabledKeys())->toBe(['website', 'inbox'])
        ->and(Modules::enabled('inbox'))->toBeTrue()
        ->and(Modules::enabled('no_such_module'))->toBeFalse()
        ->and(Modules::enabled('telegram'))->toBeFalse();

    config(['modules.enabled' => '']);
    expect(Modules::enabledKeys())->toBe([]);

    config(['modules.enabled' => ['telegram', 'made_up']]);
    expect(Modules::enabledKeys())->toBe(['telegram']);
});

it('closes every route of a switched-off module before authentication is even considered', function () {
    useShippedModules();
    $closed = [];

    foreach (Route::getRoutes() as $route) {
        $modules = collect($route->middleware())
            ->filter(fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, 'module:'))
            ->map(fn (string $middleware): string => substr($middleware, 7));

        if ($modules->isEmpty() || $modules->every(fn (string $module): bool => Modules::enabled($module))) {
            continue;
        }

        $method = collect($route->methods())->first(fn (string $verb): bool => $verb !== 'HEAD');
        $uri = '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());

        // A guest: without the module check an admin screen would redirect to sign-in and a signed page would answer 403.
        expect($this->call($method, $uri)->getStatusCode())->toBe(404, "{$method} {$uri} must be closed");
        // A route may depend on multiple modules; count every disabled gate, not only its first parent.
        $closed = array_merge($closed, $modules->reject(fn (string $module): bool => Modules::enabled($module))->all());
    }

    expect(count($closed))->toBeGreaterThanOrEqual(70)
        ->and(array_values(array_unique($closed)))->toEqualCanonicalizing(array_values(array_diff(Modules::available(), ['lead_api'])));
});

it('keeps a closed screen closed for a signed-in owner and opens it when the module is switched on', function () {
    $owner = $this->userWithRole('owner');

    useShippedModules();
    $this->actingAs($owner)->get('/admin/inbox')->assertNotFound();
    $this->actingAs($owner)->get('/admin/website/pages')->assertNotFound();
    $this->actingAs($owner)->get('/admin/leads')->assertOk();

    config(['modules.enabled' => 'lead_api,inbox']);
    $this->actingAs($owner)->get('/admin/inbox')->assertOk();
    $this->actingAs($owner)->get('/admin/website/pages')->assertNotFound();
});

it('raises the 404 before a session is started', function () {
    useShippedModules();
    expect($this->get('/portal')->assertNotFound()->headers->getCookies())->toBe([]);

    config(['modules.enabled' => 'lead_api,client_portal']);
    expect($this->get('/portal')->assertOk()->headers->getCookies())->not->toBe([]);
});

it('keeps the enquiry endpoint open while every other public endpoint is closed', function () {
    useShippedModules();

    $this->postJson('/api/public/leads', [])->assertStatus(422);

    $this->postJson('/webhooks/whatsapp', ['object' => 'whatsapp_business_account'])->assertNotFound();
    $this->get('/webhooks/whatsapp?hub.mode=subscribe')->assertNotFound();
    $this->postJson('/webhooks/telegram', ['update_id' => 1])->assertNotFound();
    $this->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertNotFound();
    $this->getJson('/api/bridge/v1/status')->assertNotFound();
    $this->get('/api/forms.php')->assertNotFound();
    $this->postJson('/api/public/chat/start', [])->assertNotFound();
    $this->get('/admin/google/login')->assertNotFound();
    $this->get('/sitemap.xml')->assertNotFound();
    $this->get('/chat/widget.js')->assertNotFound();
});

it('answers an unknown address with a plain 404 while the website module is off', function () {
    useShippedModules();
    $this->get('/no-such-page')->assertNotFound();

    // With the website on, the CMS page router owns unknown addresses and adds the trailing slash.
    config(['modules.enabled' => '*']);
    $response = $this->get('/no-such-page');
    expect($response->getStatusCode())->toBe(301)
        ->and((string) $response->headers->get('Location'))->toEndWith('/no-such-page/');
});

it('shows switched-off modules as non-clickable roadmap entries', function () {
    $owner = $this->userWithRole('owner');
    $routes = fn () => collect(AdminNavigation::for($owner))->flatMap(fn (array $group): array => $group['items'])->pluck('route');
    $titles = fn () => collect(AdminNavigation::for($owner))->pluck('title')->all();

    useShippedModules();
    expect($titles())->toBe(['Overview', 'CRM', 'Sales', 'Commerce & Fulfilment', 'Procurement & Production', 'Accounts', 'Projects', 'Inbox', 'Automation', 'Marketing', 'Pages & builder', 'Content', 'Website', 'System', 'Workspace'])
        ->and($routes()->all())->toContain('admin.leads.index', 'admin.quotes.index', 'admin.invoices.index', 'admin.accounts.workspace', 'admin.projects.index', 'admin.settings')
        ->and($routes()->intersect([
            'admin.inbox', 'admin.chat-bot', 'admin.estimations.index', 'admin.marketing.index', 'admin.automation.index',
            'admin.whatsapp.templates', 'admin.projects.portal', 'admin.website.pages', 'admin.website.theme',
        ])->all())->toBe([]);

    config(['modules.enabled' => '*']);
    expect($titles())->toContain('Inbox', 'Automation', 'Marketing', 'Pages & builder', 'Content', 'Website')
        ->and($routes()->all())->toContain('admin.inbox', 'admin.estimations.index', 'admin.projects.portal');
});

it('leaves the chat bell out of the admin header while the inbox is off', function () {
    $owner = $this->userWithRole('owner');

    useShippedModules();
    $this->actingAs($owner)->get('/admin/leads')->assertOk()->assertDontSeeLivewire('admin.conversations.live-chat-bell');

    config(['modules.enabled' => 'lead_api,inbox']);
    $this->actingAs($owner)->get('/admin/leads')->assertOk()->assertSeeLivewire('admin.conversations.live-chat-bell');
});

it('does not run the scheduled commands of switched-off modules', function () {
    Artisan::call('schedule:list');   // loads routes/console.php, where the schedule is defined
    $events = collect(app(Schedule::class)->events());
    $passes = function (string $command) use ($events): bool {
        $event = $events->first(fn (Event $event): bool => str_contains((string) $event->command, $command));
        expect($event)->not->toBeNull("{$command} is not scheduled");

        return $event->filtersPass(app());
    };

    useShippedModules();
    foreach (['woodex:retry-whatsapp-webhooks', 'woodex:whatsapp-health', 'woodex:telegram-flush', 'woodex:automation-run', 'woodex:social-publish', 'woodex:social-agent', 'woodex:seo-agent', 'woodex:site-backup'] as $command) {
        expect($passes($command))->toBeFalse("{$command} must not run");
    }
    expect($passes('woodex:heartbeat'))->toBeTrue();

    config(['modules.enabled' => '*']);
    expect($passes('woodex:social-publish'))->toBeTrue()
        ->and($passes('woodex:site-backup'))->toBeTrue();
});

it('offers no client link on a sent quotation while the client portal is off', function () {
    Storage::fake('local');
    $sales = $this->userWithRole('sales');
    $lead = Lead::factory()->create(['owner_id' => $sales->id, 'business_line' => 'furniture']);
    $quote = app(CreateQuote::class)->handle($lead->contact, BusinessLine::Furniture, $sales, $lead, null, 'Desks');
    $editor = app(QuoteEditor::class);
    $section = $quote->sections()->first() ?? $editor->addSection($quote, 'Items');
    $editor->addLine($section, ['description' => 'Desk', 'unit' => 'each', 'qty' => '2', 'rate_minor' => 5_000_000]);
    $quote->items()->where('rate_minor', 0)->each(fn ($item) => $editor->deleteLine($item));
    app(SendQuote::class)->handle($quote->refresh(), $sales);

    useShippedModules();
    Livewire::actingAs($sales)->test(QuoteBuilder::class, ['quote' => $quote->refresh()])
        ->assertOk()
        ->assertViewHas('portalUrl', fn ($url): bool => $url === null)
        ->assertViewHas('sharedPdfUrl', fn ($url): bool => $url === null)
        ->assertDontSee('Share link');

    config(['modules.enabled' => 'lead_api,client_portal']);
    Livewire::actingAs($sales)->test(QuoteBuilder::class, ['quote' => $quote->refresh()])
        ->assertOk()
        ->assertViewHas('portalUrl', fn ($url): bool => is_string($url) && str_contains($url, '/q/'))
        ->assertSee('Share link');
});
