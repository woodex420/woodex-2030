<?php

declare(strict_types=1);

use App\Domain\Conversations\Actions\ReceiveWebChatMessage;
use App\Domain\Conversations\Actions\ReceiveWhatsAppMessage;
use App\Domain\Conversations\Models\CannedReply;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Crm\Models\Contact;
use App\Domain\Marketing\Social\ProposeCaptions;
use App\Domain\Projects\Enums\ProjectStage;
use App\Domain\Website\Builder\BuilderFile;
use App\Domain\Website\Studio\RateBook;
use App\Integrations\WhatsApp\Fakes\FakeWhatsAppGateway;
use App\Integrations\WhatsApp\WhatsAppGateway;
use App\Livewire\Admin\Conversations\LiveChatBell;
use App\Livewire\Admin\Sales\EstimateBuilder;
use App\Livewire\Admin\Sales\EstimationsIndex;
use App\Settings\BotSettings;
use App\Settings\BusinessSettings;
use App\Settings\SiteSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
| The twenty screens of the modules that ship switched off, as the demo shows them since 21 November 2026 with
| every module on (DL-045), and what was cured on them the same day (DL-046, docs/woodex-os/12-DECISION-LOG.md;
| the account is docs/woodex-os/42-RELEASE-2026-11-21-THE-TWENTY-SCREENS.md).
|
| They came from the Interior studio's application like the rest, and nobody had read them as a furniture company
| would: the suite opened them only as the product ships, which is with these modules off. What a screen names is
| pinned in FurnitureOnlyTest, which now opens every screen of the menu with every module on as well. This file
| pins what a person does not see by opening a screen: what a visitor and a model are told, a time that is kept,
| a request that is sent, and a field's name.
*/

/** The installation as Woodex Furniture runs it: one line on sale, and the name the settings give. */
function asWoodexFurniture(): void
{
    config(['woodex.business_lines' => ['furniture']]);
    app(BusinessSettings::class)->fill(['brand_name' => 'Woodex Furniture', 'furniture_brand_name' => 'Woodex Furniture', 'city' => 'Lahore'])->save();
}

it('tells a model that answers a visitor whom it answers for, in the words of what is sold here', function () {
    asWoodexFurniture();
    $prompt = app(BotSettings::class)->prompt();

    expect($prompt)->toContain('You are the website assistant of Woodex Furniture, which designs and makes office furniture in Lahore, Pakistan.')
        ->toContain('desks, chairs, workstations')
        ->toContain("KNOWLEDGE from Woodex Furniture's own website pages")
        // The rules that keep a model from inventing a price or a date are the same for every trade.
        ->toContain('Never invent a price.')->toContain('Never promise a date')
        ->not->toContain('Interior')->not->toContain('interior')->not->toContain('studio')->not->toContain('kitchen');

    // Where the Interior brand is sold first, the words it was written with still stand, under the settings' name.
    config(['woodex.business_lines' => ['interior', 'furniture']]);
    app(BusinessSettings::class)->fill(['brand_name' => 'Woodex Interior'])->save();
    expect(app(BotSettings::class)->prompt())->toContain('You are the website assistant of Woodex Interior, an interior design and furniture studio in Lahore, Pakistan.');

    // What the owner writes replaces the built-in words whole.
    $settings = app(BotSettings::class);
    $settings->system_prompt = 'Answer only in Urdu.';
    expect($settings->prompt())->toBe('Answer only in Urdu.');
});

it('drafts a caption for a furniture company, not for an interior design studio', function () {
    asWoodexFurniture();
    $settings = app(BotSettings::class);
    $settings->fill(['provider' => 'openai', 'api_key' => 'sk-test-key'])->save();
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '{"text":"A caption","text_ur":"ایک کیپشن"}']]]])]);

    expect(app(ProposeCaptions::class)->propose('Forty workstations for a bank'))->toBe(['text' => 'A caption', 'text_ur' => 'ایک کیپشن']);

    Http::assertSent(function ($request): bool {
        $sent = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

        return str_contains($sent, 'Woodex Furniture, a company that designs and makes office furniture')
            && str_contains($sent, 'Our notes: Forty workstations for a bank')
            && ! str_contains($sent, 'studio') && ! str_contains($sent, 'Studio') && ! str_contains($sent, 'interior');
    });
});

it('asks a visitor of the website what furniture they are looking for', function () {
    asWoodexFurniture();
    expect(ReceiveWebChatMessage::askWhatIsNeeded())->toBe('What are you looking for — for example office chairs, desks, workstations, or a complete office fit-out?');

    config(['woodex.business_lines' => ['interior', 'furniture']]);
    expect(ReceiveWebChatMessage::askWhatIsNeeded())->toContain('a full home interior');
});

it('tells a client where their project stands without naming a room or a 3D view', function () {
    foreach (ProjectStage::cases() as $stage) {
        expect($stage->clientLine())->not->toContain('3D')->not->toContain('your space')->not->toContain('paint');
    }
});

it('fills the name and the hours in a saved reply, under the new names and under the old ones', function () {
    asWoodexFurniture();
    expect(CannedReply::VARIABLES)->toBe(['{first_name}', '{business_name}', '{business_hours}', '{quote_link}']);

    $contact = Contact::factory()->create(['name' => 'Hina Qureshi', 'phone' => '+923001234567', 'whatsapp' => '+923001234567']);
    $conversation = Conversation::factory()->create(['contact_id' => $contact->id]);

    $new = new CannedReply(['body' => 'Hello {first_name}, this is {business_name}. We are open {business_hours}.']);
    $old = new CannedReply(['body' => 'Hello {first_name}, this is {studio_name}. We are open {studio_hours}.']);

    $business = app(BusinessSettings::class);
    expect($new->renderFor($conversation, $business))->toStartWith('Hello Hina, this is Woodex Furniture. We are open ')
        ->not->toContain('{')
        // A reply saved before the names changed is sent with the same words.
        ->and($old->renderFor($conversation, $business))->toBe($new->renderFor($conversation, $business));
});

it('keeps the moment a WhatsApp message was sent, not the moment five hours before it', function () {
    config()->set('services.whatsapp', [
        'token' => 'test-token', 'phone_number_id' => '1234567890', 'waba_id' => '987654321', 'app_secret' => 'app-secret',
        'verify_token' => 'verify-me', 'api_version' => 'v23.0', 'api_base' => 'https://graph.facebook.com',
    ]);
    $this->app->instance(WhatsAppGateway::class, new FakeWhatsAppGateway);

    // Three in the afternoon in Lahore; the customer wrote at one.
    $this->travelTo(CarbonImmutable::parse('2026-11-21 15:00:00', 'Asia/Karachi'));
    $sent = CarbonImmutable::parse('2026-11-21 13:00:00', 'Asia/Karachi');

    app(ReceiveWhatsAppMessage::class)->handle([
        'messaging_product' => 'whatsapp',
        'contacts' => [['wa_id' => '923224007688', 'profile' => ['name' => 'Bilal Mirza']]],
        'messages' => [['from' => '923224007688', 'id' => 'wamid.TIME1', 'timestamp' => (string) $sent->timestamp, 'type' => 'text', 'text' => ['body' => 'Rate bata dein?']]],
    ]);

    $conversation = Conversation::query()->sole();

    // As the record holds it, and as the database holds it: one o'clock, on every server.
    expect($conversation->last_message_at->equalTo($sent))->toBeTrue('last message: '.$conversation->last_message_at->toIso8601String())
        ->and(DB::table('conversations')->where('id', $conversation->id)->value('last_message_at'))->toStartWith('2026-11-21 13:00:00')
        // The customer may be answered freely for 24 hours from when they wrote: until one the next afternoon,
        // not until eight the next morning.
        ->and($conversation->window_expires_at->equalTo($sent->addHours(24)))->toBeTrue('window: '.$conversation->window_expires_at->toIso8601String());
});

it('asks the server for a website chat only while a visitor can start one', function () {
    $this->actingAs($this->userWithRole('owner'));

    app(SiteSettings::class)->fill(['chat_widget_enabled' => false])->save();
    Livewire::test(LiveChatBell::class)->assertOk()
        // The bell is there and opens on a click; it does not ask every ten seconds from every screen.
        ->assertSee('aria-label="Website chat"', false)
        ->assertDontSee('wire:poll', false);

    app(SiteSettings::class)->fill(['chat_widget_enabled' => true])->save();
    Livewire::test(LiveChatBell::class)->assertSee('wire:poll.10s.visible="poll"', false);

    // A person who may not read the inbox is given no bell and no question to the server.
    $this->actingAs($this->userWithRole('accountant'));
    Livewire::test(LiveChatBell::class)->assertDontSee('wire:poll', false)->assertDontSee('aria-label="Website chat"', false);
});

it('says that there is no rate list, offers nothing that cannot work, and names no folder of the server', function () {
    config(['website.rate_book_path' => storage_path('framework/testing/no-such-rate-book.js')]);
    expect(RateBook::exists())->toBeFalse();
    $this->actingAs($this->userWithRole('owner'));

    Livewire::withQueryParams(['tab' => 'rates'])->test(EstimationsIndex::class)
        ->assertOk()
        ->assertSee('No rate list yet')
        ->assertSee("Woodex Furniture's rates have not been entered yet", false)
        ->assertDontSee('Publish rates')->assertDontSee('Add service')->assertDontSee('Open public estimator')
        ->assertDontSee('rate book is missing')
        ->assertDontSee(storage_path(), false)->assertDontSee(base_path(), false)
        // Asking by hand for what the screen no longer offers changes nothing and shows no fault.
        ->call('reloadRates')->assertHasNoErrors();

    // The reader itself no longer puts the folder into a sentence a screen may print.
    expect(fn () => RateBook::read())->toThrow(UnexpectedValueException::class, 'The estimator rate book is missing.');
});

it('names every field of a new estimate, and says why none can be saved when no service has a rate', function () {
    DB::table('estimator_rates')->delete();
    $this->actingAs($this->userWithRole('owner'));

    $html = Livewire::test(EstimateBuilder::class)->assertOk()
        ->assertSee('No service has a rate yet, so an estimate cannot be saved here.')
        // The Interior studio's examples are gone from the fields.
        ->assertDontSee('marla', false)->assertDontSee('sq ft of covered area', false)->assertDontSee('MEP by others', false)
        ->html();

    expect(fieldsWithoutAName($html))->toBe([]);
});

/**
 * The fields of a page that nothing names: no label for their id, no label around them, no aria-label and no title.
 * A hidden field, a button and a checkbox or radio inside its own label are not counted.
 *
 * @return list<string>
 */
function fieldsWithoutAName(string $html): array
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xpath = new DOMXPath($document);

    $labelled = [];
    foreach ($xpath->query('//label[@for]') as $label) {
        $labelled[$label->getAttribute('for')] = true;
    }

    $unnamed = [];
    foreach ($xpath->query('//input | //select | //textarea') as $field) {
        if (in_array($field->getAttribute('type'), ['hidden', 'submit', 'button', 'file'], true)) {
            continue;
        }
        $named = $field->getAttribute('aria-label') !== ''
            || $field->getAttribute('aria-labelledby') !== ''
            || $field->getAttribute('title') !== ''
            || ($field->getAttribute('id') !== '' && isset($labelled[$field->getAttribute('id')]))
            || $xpath->query('ancestor::label', $field)->length > 0;
        if (! $named) {
            $unnamed[] = $field->nodeName.'#'.$field->getAttribute('id').' '.$field->getAttribute('wire:model').$field->getAttribute('wire:model.live').' '.$field->getAttribute('placeholder');
        }
    }

    return $unnamed;
}

it('gives the screens that draw their own document one heading, the demo notice, and the name from the settings', function (string $screen) {
    asWoodexFurniture();
    $this->actingAs($this->userWithRole('owner'));

    $html = (string) $this->get('/admin/website/'.$screen)->assertOk()->getContent();

    // The sign-in shell of the ported admin is never shown inside Woodex OS: its six headings were the page's.
    expect(substr_count($html, '<h1'))->toBe(0, 'the script draws the one heading of the screen')
        ->and($html)->toContain('brand: "Woodex Furniture"')
        ->not->toContain('Woodex Interior')->not->toContain('Run the whole studio');

    // A real system says nothing about a demo; a demo says it on these screens as on every other.
    expect($html)->not->toContain('data-demo-notice');
    if (config('database.default') === 'sqlite') {
        config(['preview.synthetic' => true]);
        $this->get('/admin/website/'.$screen)->assertOk()->assertSee('data-demo-notice', false)->assertSee('Demo: nothing typed here is kept');
    }
})->with(['blog', 'portfolio', 'forms']);

it('opens the page builder with one heading, a name on every field, and no link that leads nowhere', function () {
    $this->actingAs($this->userWithRole('owner'));
    $html = (string) $this->get(route('admin.website.builder', [], false))->assertOk()->getContent();

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('<h1 class="brand">')
        // "View page" has no address until a page is open; it is not shown until then.
        ->toContain('<a class="btn" id="view-live" hidden>View page</a>')
        ->and(fieldsWithoutAName(substr($html, (int) strpos($html, '<div id="app"'))))->toBe([]);

    if (config('database.default') === 'sqlite') {
        config(['preview.synthetic' => true]);
        $this->get(route('admin.website.builder', [], false))->assertOk()->assertSee('data-demo-notice', false);
    }
});

it('names every search field the Website Studio and the page builder draw by script', function () {
    // The crawl reads a page as it is drawn; a field a script writes later is seen only when someone opens that
    // pane. So the scripts themselves are read: a search field with a placeholder alone has no name for a
    // screen reader once a letter is typed.
    $unnamed = [];
    $scripts = array_merge(glob(public_path('builder/*.js')) ?: [], glob(public_path('assets/admin-v21/*.js')) ?: []);
    expect(count($scripts))->toBeGreaterThan(20);

    foreach ($scripts as $script) {
        preg_match_all('/<input[^>]*type=[\'"]search[\'"][^>]*>/', (string) file_get_contents($script), $fields);
        foreach ($fields[0] as $field) {
            if (! str_contains($field, 'aria-label=')) {
                $unnamed[] = basename($script).': '.$field;
            }
        }
    }

    expect($unnamed)->toBe([]);
});

it('says on each Website screen what the Website screens manage', function (string $route) {
    $this->actingAs($this->userWithRole('owner'));

    $this->get(route($route, [], false))->assertOk()
        ->assertSee('data-website-scope', false)
        ->assertSee('These screens are for pages this admin serves itself.')
        ->assertSee("Woodex's shop is a separate application, and nothing here changes it.", false);
})->with([
    'admin.website.pages', 'admin.website.theme', 'admin.website.seo.overview', 'admin.website.seo', 'admin.website.speed',
    'admin.website.health', 'admin.website.settings', 'admin.website.redirects', 'admin.website.find-replace',
]);

it('says that there is no page yet where a list of pages is empty, and not that a search found none', function () {
    // An empty list has two reasons. With no page at all, "clear the search" sends a person looking for a
    // search they never typed, and "every page passes" praises pages that are not there.
    $this->actingAs($this->userWithRole('owner'));
    // What the product ships with, and what the demo has: this admin serves no page of its own.
    expect(BuilderFile::pages())->toBe([]);

    foreach (['admin.website.pages', 'admin.website.seo.overview', 'admin.website.seo'] as $route) {
        $this->get(route($route, [], false))->assertOk()
            ->assertSee('No page yet')
            ->assertDontSee('No page matches')
            ->assertDontSee('Every page passes');
    }

    $this->get(route('admin.website.seo.overview', ['tab' => 'metadata'], false))->assertOk()
        ->assertSee('No page yet')
        ->assertDontSee('No pages match these review filters');
});

it('names the fields of the redirects screen and tells the form when a page is picked', function () {
    $this->actingAs($this->userWithRole('owner'));
    $html = (string) $this->get(route('admin.website.redirects', [], false))->assertOk()->getContent();
    $main = substr($html, (int) strpos($html, '<main'));

    expect(fieldsWithoutAName($main))->toBe([])
        // The list writes its choice into the field above; without the event the form sent what had been typed.
        ->and($main)->toContain("dispatchEvent(new Event('input', { bubbles: true }))")
        ->not->toContain('interior-design');
});

/*
| No sentence of the application says "studio". The screens are opened by FurnitureOnlyTest; this reads what a
| screen shows only after a click, and what is never a screen: a message, a PDF, a prompt, a notification.
*/
it('writes the word studio in no view and in no sentence of the application', function () {
    // Names of things, not words to a person.
    $names = [
        'Website Studio', 'website-studio', 'WX_STUDIO', 'admin.website.studio', 'website/studio', 'studio-retired', 'btn-studio',
        'LM Studio', // a product a person may connect, by its own name
    ];
    // Kept on purpose, each with its reason.
    $kept = [
        'app/Support/Brand.php' => 'the mark of the Interior brand, printed only where that brand is sold',
        'app/Settings/BotSettings.php' => 'the Interior words of the built-in persona, used only where that brand is sold first',
        'app/Domain/Marketing/Social/ProposeCaptions.php' => 'the Interior words of the caption prompt, used only where that brand is sold first',
        'app/Domain/Conversations/Models/CannedReply.php' => '{studio_name} and {studio_hours} are still filled in a reply saved under those names',
        'app/Domain/Automation/Models/AutomationStep.php' => '{studio_name} is still filled in a step saved under that name',
        'app/Domain/System/Import/V21Importer.php' => 'the importer of the Interior studio\'s old records writes that system\'s own words',
        'app/Domain/Website/Builder/ArticleFile.php' => 'the key of a block of an article as the Interior website stored it',
        'app/Domain/Website/Studio/StudioContent.php' => 'the key of a block of an article as the Interior website stored it',
        'app/Console/Commands/FrontendTemplates.php' => 'a comment inside a heredoc of a developer\'s command',
    ];

    $found = [];
    $clean = fn (string $text): string => str_replace($names, '', $text);

    // 1. Every string of the application's PHP.
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $relative = 'app/'.ltrim(str_replace(app_path(), '', $file->getPathname()), '/');
        if ($file->getExtension() !== 'php' || isset($kept[$relative])) {
            continue;
        }
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true) && stripos($clean($token[1]), 'studio') !== false) {
                $found[] = $relative.':'.$token[2].' '.trim(substr($token[1], 0, 90));
            }
        }
    }

    // 2. Every view, without its Blade comments (a comment may say where the code came from).
    $views = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS));
    foreach ($views as $view) {
        if (! str_ends_with($view->getFilename(), '.blade.php')) {
            continue;
        }
        $text = $clean((string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($view->getPathname())));
        if (preg_match_all('/.{0,50}studio.{0,40}/i', $text, $hits)) {
            foreach ($hits[0] as $hit) {
                $found[] = 'resources/views'.str_replace(resource_path('views'), '', $view->getPathname()).' '.trim($hit);
            }
        }
    }

    expect($found)->toBe([]);

    // The list of what is kept names only files that are there: a file that goes takes its line with it.
    foreach (array_keys($kept) as $path) {
        expect(is_file(base_path($path)))->toBeTrue($path.' is kept by name and is gone');
    }
});
