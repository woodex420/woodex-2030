<?php

declare(strict_types=1);

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Projects\Models\Project;
use App\Domain\Sales\Actions\AcceptQuote;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Enums\AcceptanceChannel;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuotePdf;
use App\Domain\Sales\Services\QuoteSnapshot;
use App\Domain\Website\Models\Page;
use App\Livewire\Admin\Crm\LeadShow;
use App\Livewire\Admin\Projects\ProjectShow;
use App\Livewire\Admin\System\Integrations;
use App\Livewire\Admin\System\SettingsPage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;

/*
| Code of a switched-off module that an open screen could still reach (list line T-034).
|
| A switched-off module is closed at the door: every one of its routes answers 404 before a session is started
| (ModuleSwitchTest). That covers the estimator and its rate book, the inbox, the website's own screens. It does
| not cover what an OPEN screen offers on behalf of a closed module. Two such places were found:
|
|   - the project a sale opens offered "Case study": a draft page of the public website, written from the
|     Interior studio's template, for a website that is switched off;
|   - Settings showed the sections "WhatsApp" (the Cloud API's keys and costs) and "Bot" (the chat bot's prompt
|     and what it knows, written for the Interior studio) although neither module is on;
|   - Integrations listed a card for Telegram, WhatsApp, Google, Gmail, Drive, the Facebook page and the AI
|     bridge, each with its buttons — one of them makes an access key for an endpoint that is closed.
|
| Each is closed here as a route is: not offered, and "not found" when asked for anyway. Two actions stay open on
| purpose, because they only take away: removing a stored connection and revoking a bridge key.
|
| The other Interior code the list names is asked for here too, so that the answer is on record:
|
|   - the two Interior quotation designs: a quotation of the line on sale is drawn on the Furniture sheet, and a
|     new quotation cannot be started on another line (FurnitureOnlyTest: the screen refuses the line);
|   - the estimator and its rate book: every address answers "not found", and so does the one action on a
|     lead that would price an estimate.
*/

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    Mail::fake();
    // The product as it ships.
    config(['modules.enabled' => 'lead_api', 'woodex.business_lines' => ['furniture']]);
    $this->owner = $this->userWithRole('owner');
});

/** The project that an accepted Furniture quotation opens. */
function projectOfASale(): Project
{
    $lead = Lead::factory()->create([
        'owner_id' => test()->owner->id, 'business_line' => 'furniture', 'service' => 'office-furniture',
        'message' => 'Twelve workstations.', 'created_at' => now()->subDay(),
    ]);
    $lead->contact->update(['name' => 'Ayesha Khan', 'phone' => '0322 4000768', 'whatsapp' => '0322 4000768']);
    $quote = app(CreateQuote::class)->handle($lead->contact, BusinessLine::Furniture, test()->owner, $lead, null, 'Office furniture');
    app(QuoteEditor::class)->updateLine($quote->items()->firstOrFail(), [
        'product_name' => 'Executive workstation', 'description' => 'Laminate top.', 'unit' => 'nos', 'qty' => '12', 'rate_minor' => 4_500_000,
    ]);
    app(SendQuote::class)->handle($quote->refresh(), test()->owner, 'link', false);
    app(AcceptQuote::class)->handle($quote->refresh(), 'Ayesha Khan', null, null, null, test()->owner, null, AcceptanceChannel::Phone);

    return Project::query()->where('quote_id', $quote->id)->sole();
}

it('offers no case study on a project while the public website is off, and writes none when asked', function () {
    $project = projectOfASale();

    Livewire::actingAs($this->owner)->test(ProjectShow::class, ['project' => $project])
        ->assertDontSee('Case study')
        ->assertDontSee('portfolio')
        ->call('toggleConsent', true)
        ->assertNotFound();

    Livewire::actingAs($this->owner)->test(ProjectShow::class, ['project' => $project])
        ->call('draftCaseStudy')
        ->assertNotFound();

    expect($project->refresh()->case_study_consent)->toBeFalsy()
        ->and(Page::query()->count())->toBe(0);
});

it('offers the case study again where the public website is switched on', function () {
    config(['modules.enabled' => 'lead_api,website']);
    $project = projectOfASale();

    Livewire::actingAs($this->owner)->test(ProjectShow::class, ['project' => $project])
        ->assertSee('Case study')
        ->call('toggleConsent', true)
        ->assertHasNoErrors();

    expect($project->refresh()->case_study_consent)->toBeTrue();
});

it('shows no WhatsApp and no Bot section in Settings while their modules are off', function () {
    Livewire::actingAs($this->owner)->test(SettingsPage::class)
        ->assertSeeInOrder(['Business', 'Sales rules', 'Lead rules', 'Notifications'])
        ->assertDontSeeHtml('wire:click="$set(\'tab\', \'whatsapp\')"')
        ->assertDontSeeHtml('wire:click="$set(\'tab\', \'bot\')"');
});

it('opens the first section when the address names a closed one, and shows nothing of it', function (string $section, string $marker) {
    Livewire::actingAs($this->owner)->withQueryParams(['tab' => $section])->test(SettingsPage::class)
        ->assertSet('tab', 'business')
        ->assertDontSee($marker);
})->with([
    'the WhatsApp Cloud API' => ['whatsapp', 'Phone number id'],
    'the chat bot' => ['bot', 'System prompt'],
]);

it('opens that section, with the same words, where its module is on', function (string $modules, string $section, string $marker) {
    config(['modules.enabled' => $modules]);

    Livewire::actingAs($this->owner)->withQueryParams(['tab' => $section])->test(SettingsPage::class)
        ->assertSet('tab', $section)
        ->assertSee($marker);
})->with([
    'the WhatsApp Cloud API' => ['lead_api,whatsapp', 'whatsapp', 'Phone number id'],
    'the chat bot' => ['lead_api,inbox', 'bot', 'System prompt'],
]);

it('answers "not found" to every action of a closed section', function (string $action, array $arguments) {
    Livewire::actingAs($this->owner)->test(SettingsPage::class)
        ->call($action, ...$arguments)
        ->assertNotFound();
})->with([
    'save the WhatsApp keys' => ['saveWhatsApp', []],
    'test the WhatsApp connection' => ['testWhatsAppConnection', []],
    'forget the WhatsApp keys' => ['forgetWhatsApp', []],
    'save the bot' => ['saveBot', []],
    'refresh what the bot knows' => ['syncBotKnowledge', []],
    'add to what the bot knows' => ['addBotKnowledge', []],
    'delete from what the bot knows' => ['deleteBotKnowledge', [1]],
]);

it('shows each section again when its module is switched on', function (string $modules, array $shown, array $hidden) {
    config(['modules.enabled' => $modules]);

    $screen = Livewire::actingAs($this->owner)->test(SettingsPage::class);

    foreach ($shown as $section) {
        $screen->assertSeeHtml('wire:click="$set(\'tab\', \''.$section.'\')"');
    }
    foreach ($hidden as $section) {
        $screen->assertDontSeeHtml('wire:click="$set(\'tab\', \''.$section.'\')"');
    }
})->with([
    'WhatsApp on: its keys, and the bot that answers there' => ['lead_api,whatsapp', ['whatsapp', 'bot'], []],
    'the inbox on: the bot of the chat widget, no WhatsApp keys' => ['lead_api,inbox', ['bot'], ['whatsapp']],
]);

it('lists on Integrations only what belongs to the core or to a module that is on', function () {
    Livewire::actingAs($this->owner)->test(Integrations::class)
        ->assertSee('Email (SMTP)')
        ->assertSee('Website lead forms')
        ->assertSee('Cloudflare Turnstile')
        ->assertSee('Webhooks')
        ->assertDontSee('Telegram bot')
        ->assertDontSee('WhatsApp Cloud API')
        ->assertDontSee('Google account')
        ->assertDontSee('Gmail')
        ->assertDontSee('Google Drive')
        ->assertDontSee('Facebook Page')
        ->assertDontSee('AI bridge (MCP)')
        // No group is named that has no card left.
        ->assertDontSeeHtml('wire:click="$set(\'appCategory\', \'google\')"')
        ->assertDontSeeHtml('wire:click="$set(\'appCategory\', \'social\')"')
        ->assertSeeHtml('wire:click="$set(\'appCategory\', \'communication\')"');
});

it('answers "not found" to what a hidden Integrations card could do', function (string $action, array $arguments) {
    Livewire::actingAs($this->owner)->test(Integrations::class)
        ->call($action, ...$arguments)
        ->assertNotFound();
})->with([
    'open the AI bridge setup' => ['openMcpSetup', []],
    'make a key for the AI bridge' => ['createBridgeKey', []],
    'open the Google setup' => ['openGoogleSetup', []],
    'connect Google Workspace' => ['openConnector', ['google_workspace']],
    'test Google Workspace' => ['checkConnector', ['google_workspace']],
    'connect the Facebook page' => ['openConnector', ['facebook_page']],
    'test WhatsApp' => ['testWhatsApp', []],
    'send a Telegram test' => ['testTelegram', []],
    'set the Telegram webhook' => ['setTelegramWebhook', []],
    'check the Telegram connection' => ['checkTelegramConnection', []],
    'read the Telegram configuration' => ['checkTelegramConfiguration', []],
    'reload the Telegram configuration' => ['reloadTelegramConfiguration', []],
    'the details of the AI bridge' => ['openDetails', ['mcp']],
    'the details of WhatsApp' => ['openDetails', ['whatsapp']],
]);

it('still opens what belongs to the core on Integrations', function () {
    Livewire::actingAs($this->owner)->test(Integrations::class)
        ->call('openDetails', 'mail')
        ->assertOk()
        ->assertSet('detailKey', 'mail')
        ->call('openWebhooks')
        ->assertOk();
});

it('lists a card again when its module is switched on', function (string $modules, array $shown, array $hidden) {
    config(['modules.enabled' => $modules]);

    $screen = Livewire::actingAs($this->owner)->test(Integrations::class);

    foreach ($shown as $name) {
        $screen->assertSee($name);
    }
    foreach ($hidden as $name) {
        $screen->assertDontSee($name);
    }
})->with([
    'WhatsApp' => ['lead_api,whatsapp', ['WhatsApp Cloud API'], ['AI bridge (MCP)', 'Google account']],
    'the AI bridge' => ['lead_api,mcp_bridge', ['AI bridge (MCP)'], ['WhatsApp Cloud API', 'Gmail']],
    'Google' => ['lead_api,google', ['Google account', 'Gmail', 'Google Drive'], ['Facebook Page']],
    'marketing' => ['lead_api,marketing', ['Facebook Page'], ['Google account']],
    'no public form at all: no spam check to set up' => ['estimator', ['Email (SMTP)'], ['Cloudflare Turnstile', 'Website lead forms']],
]);

it('draws a quotation on the Furniture sheet and on neither Interior design', function () {
    $drawn = [];
    View::composer(['pdf.furniture-document', 'pdf.interior-quotation-v2', 'pdf.interior-quotation-v3', 'pdf.quote-v21', 'pdf.quote-legacy'],
        function ($view) use (&$drawn): void {
            $drawn[] = $view->name();
        });

    $quote = projectOfASale()->quote;
    $html = app(QuotePdf::class)->html(app(QuoteSnapshot::class)->build($quote));

    expect($html)->toContain('data-furniture-document="quotation"')
        ->and(array_values(array_unique($drawn)))->toBe(['pdf.furniture-document']);
});

it('answers "not found" at every address of the estimator and its rate book', function (string $address) {
    $this->actingAs($this->owner)->get($address)->assertNotFound();
})->with([
    'the estimations' => ['/admin/estimations'],
    'the rate book' => ['/admin/estimations?tab=rates'],
    'a new estimate' => ['/admin/estimations/new'],
    'the rate book, by its older address' => ['/admin/website/estimator'],
]);

it('prices no estimate from a lead while the estimator is off', function () {
    $lead = Lead::factory()->create(['owner_id' => $this->owner->id, 'business_line' => 'furniture', 'service' => 'office-furniture', 'message' => 'Twelve workstations.']);

    Livewire::actingAs($this->owner)->test(LeadShow::class, ['lead' => $lead])
        ->assertDontSee('Estimations')
        ->call('startQuoteFromEstimate')
        ->assertNotFound();

    config(['modules.enabled' => 'lead_api,estimator']);

    Livewire::actingAs($this->owner)->test(LeadShow::class, ['lead' => $lead])
        ->call('startQuoteFromEstimate')
        ->assertHasErrors('estimate');
});
