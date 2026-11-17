<?php

declare(strict_types=1);

use App\Domain\Crm\Actions\ScheduleSiteVisit;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Projects\Services\PortalLinks;
use App\Domain\Sales\Actions\AcceptQuote;
use App\Domain\Sales\Actions\CreateFurnitureInvoice;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Models\DescriptionTemplate;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Services\InvoiceLinks;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuoteLinks;
use App\Domain\System\Notifications\StaffInvitation;
use App\Livewire\Admin\Crm\ContactShow;
use App\Livewire\Admin\Crm\LeadShow;
use App\Livewire\Admin\Crm\LeadsIndex;
use App\Livewire\Admin\Sales\DescriptionLibrary;
use App\Livewire\Admin\Sales\DocumentDesigner;
use App\Livewire\Admin\Sales\InvoiceCreate;
use App\Livewire\Admin\Sales\QuoteCreate;
use App\Livewire\Admin\Sales\QuoteTemplates;
use App\Livewire\Admin\Sales\RateBook;
use App\Livewire\Admin\Sales\RecurringInvoices;
use App\Settings\BusinessSettings;
use App\Support\AdminNavigation;
use App\Support\Brand;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| This product is Woodex Furniture's: nothing of Woodex Interior belongs in it (owner decision D-008).
|
| The application came from the Interior studio's, which sold three business lines and started every new record
| on the Interior one. An installation now sells what config('woodex.business_lines') names, and the shipped
| default names Furniture alone. phpunit.xml names all three, so the inherited suite keeps exercising the code
| that tells lines apart; these tests pin the shipped default, screen by screen.
|
| On 17 November 2026 a browser found the word "Interior" on 30 of the 122 screens an owner can open on the demo:
| as a choice in ten lists, as the first brand of a new quotation, as the line a new invoice starts on, in the
| WhatsApp greeting on every lead ("…from Woodex Interior"), and as an account of the chart.
*/

/** The installation as it ships: Furniture alone, optional modules off, seeded without the Interior reference rows. */
function sellFurnitureAlone(): void
{
    config(['woodex.business_lines' => ['furniture'], 'modules.enabled' => 'lead_api']);

    // The suite's own database was seeded for three lines. Empty what a Furniture installation never receives:
    // the Interior reference rows (ProductionSeeder::INTERIOR_REFERENCE) and the Interior revenue account.
    foreach (['catalog_items', 'estimator_rates', 'quote_templates', 'canned_replies', 'automation_sequences', 'theme_tokens', 'page_templates'] as $table) {
        DB::table($table)->delete();
    }
    DB::table('chart_of_accounts')->where('business_line', 'interior')->delete();
}

/** A page as a person meets it: entities decoded and links unescaped, so a WhatsApp greeting reads as its words. */
function pageWords(string $html): string
{
    return rawurldecode(str_replace('+', ' ', html_entity_decode($html, ENT_QUOTES | ENT_HTML5)));
}

it('ships selling Furniture alone', function () {
    $saved = [$_ENV['WOODEX_BUSINESS_LINES'] ?? null, $_SERVER['WOODEX_BUSINESS_LINES'] ?? null, getenv('WOODEX_BUSINESS_LINES')];
    unset($_ENV['WOODEX_BUSINESS_LINES'], $_SERVER['WOODEX_BUSINESS_LINES']);
    putenv('WOODEX_BUSINESS_LINES');

    try {
        expect((require config_path('woodex.php'))['business_lines'])->toBe(['furniture']);
    } finally {
        if ($saved[0] !== null) {
            $_ENV['WOODEX_BUSINESS_LINES'] = $saved[0];
        }
        if ($saved[1] !== null) {
            $_SERVER['WOODEX_BUSINESS_LINES'] = $saved[1];
        }
        if ($saved[2] !== false) {
            putenv('WOODEX_BUSINESS_LINES='.$saved[2]);
        }
    }
});

it('reads the lines on sale from configuration, in their order, and never sells nothing', function () {
    config(['woodex.business_lines' => ['furniture']]);
    expect(BusinessLine::offered())->toBe([BusinessLine::Furniture])
        ->and(BusinessLine::primary())->toBe(BusinessLine::Furniture)
        ->and(BusinessLine::severalOffered())->toBeFalse()
        ->and(BusinessLine::offeredBrandValues())->toBe(['furniture'])
        ->and(BusinessLine::Interior->isOffered())->toBeFalse();

    // The order is the order of the list: the first line is the one a new record starts on.
    config(['woodex.business_lines' => ['project', 'furniture', 'project', 'no-such-line', 'interior']]);
    expect(BusinessLine::offered())->toBe([BusinessLine::Project, BusinessLine::Furniture, BusinessLine::Interior])
        ->and(BusinessLine::primary())->toBe(BusinessLine::Project)
        // Interior and Project quote, invoice and print as one brand.
        ->and(BusinessLine::offeredBrandValues())->toBe(['interior', 'furniture'])
        ->and(BusinessLine::primaryBrand())->toBe(BusinessLine::Interior);

    // An empty or misspelt setting does not leave the admin without a line to sell.
    foreach ([[], ['intrior'], null, ''] as $broken) {
        config(['woodex.business_lines' => $broken]);
        expect(BusinessLine::offered())->toBe([BusinessLine::Furniture]);
    }
});

it('names a line beside a record only where that tells records apart', function () {
    config(['woodex.business_lines' => ['furniture']]);
    expect(BusinessLine::Furniture->tag())->toBe('')
        // A record of a line that is not sold here still says what it is.
        ->and(BusinessLine::Interior->tag())->toBe('Interior')
        ->and(BusinessLine::optionsFor(null))->toBe(['furniture' => 'Furniture'])
        ->and(BusinessLine::optionsFor(BusinessLine::Interior))->toBe(['furniture' => 'Furniture', 'interior' => 'Interior'])
        ->and(BusinessLine::brandOptionsFor(BusinessLine::Project))->toBe(['furniture' => 'Furniture', 'interior' => 'Interior']);

    config(['woodex.business_lines' => ['interior', 'furniture', 'project']]);
    expect(BusinessLine::Furniture->tag())->toBe('Furniture')
        ->and(BusinessLine::optionsFor(null))->toHaveCount(3);

    $lead = new Lead(['business_line' => 'furniture', 'service' => 'office-chairs']);
    expect($lead->lineAndService())->toBe('Furniture · Office Chairs');
    config(['woodex.business_lines' => ['furniture']]);
    expect($lead->lineAndService())->toBe('Office Chairs')
        ->and((new Lead(['business_line' => 'furniture']))->lineAndService())->toBe('');
});

it('names nothing of Interior, and no studio, on any screen of the menu, on a lead, a contact, a new quotation or a new invoice', function (string $role) {
    sellFurnitureAlone();
    $user = $this->userWithRole($role);
    $contact = Contact::factory()->create(['name' => 'Hina Qureshi', 'phone' => '+923001234567', 'whatsapp' => '+923001234567']);
    // The factory writes the Interior studio's services and enquiries at random; this lead is a Furniture one.
    $lead = Lead::factory()->for($contact)->create(['owner_id' => $user->id, 'business_line' => 'furniture', 'service' => 'office-chairs', 'message' => 'Twelve chairs for the head office.']);
    $this->actingAs($user);

    $queue = [
        '/admin',
        route('admin.leads.show', $lead, false),
        route('admin.contacts.show', $contact, false),
        route('admin.quotes.create', [], false),
        route('admin.quotes.create', ['lead' => $lead->id], false),
        route('admin.invoices.create', [], false),
        route('admin.leads.index', ['new' => 1], false),
    ];
    foreach (AdminNavigation::for($user) as $group) {
        foreach ($group['items'] as $item) {
            $queue[] = route($item['route'], [], false);
        }
    }

    $named = [];
    $opened = 0;
    $seen = [];
    while ($queue !== []) {
        $page = array_shift($queue);
        if (isset($seen[$page])) {
            continue;
        }
        $seen[$page] = true;
        $response = $this->get($page);
        if ($response->status() !== 200) {
            continue; // a screen this role may not open is another test's subject (AdminRouteAccessTest)
        }
        $opened++;
        $html = (string) $response->getContent();

        // Everything the page carries: what is shown, what is folded away, the choices of every list, and the
        // links that leave the admin. Capital letters count; "interior" as a stored value does not appear here.
        $words = pageWords($html);
        if (($at = strpos($words, 'Interior')) !== false) {
            $named[] = $page.' → …'.trim(preg_replace('/\s+/', ' ', strip_tags(substr($words, max(0, $at - 70), 150)))).'…';
        }

        // A furniture company is not "the studio" either. "Website Studio" is the name of a part of the menu.
        $unnamed = str_replace(['Website Studio', 'website-studio'], '', $words);
        if (($at = stripos($unnamed, 'studio')) !== false) {
            $named[] = $page.' → …'.trim(preg_replace('/\s+/', ' ', strip_tags(substr($unnamed, max(0, $at - 70), 150)))).'…';
        }

        // The tabs of this screen (the hubs: Leads, Quotations, Documents, Accounts, System…), one level deep.
        if (! str_contains($page, '?')) {
            preg_match_all('/href="([^"]*'.preg_quote($page, '/').'\?tab=[a-z0-9_-]+)"/i', $html, $tabs);
            foreach (array_unique($tabs[1]) as $tab) {
                $queue[] = (string) parse_url(html_entity_decode($tab), PHP_URL_PATH).'?'.parse_url(html_entity_decode($tab), PHP_URL_QUERY);
            }
        }
    }

    expect($opened)->toBeGreaterThanOrEqual(3)
        ->and($named)->toBe([]);
})->with(['owner', 'admin', 'sales_manager', 'sales', 'designer_pm', 'accountant', 'editor', 'support']);

it('starts a lead on Furniture and offers no other line', function () {
    sellFurnitureAlone();
    $this->actingAs($this->userWithRole('sales_manager'));

    Livewire::test(LeadsIndex::class)
        ->assertSet('newLine', 'furniture')
        ->set('showCreate', true)
        ->assertDontSee('Business line')
        ->assertDontSee('All lines')
        ->set('newName', 'Bilal Ahmed')
        ->set('newPhone', '0300 1234567')
        ->call('createLead')
        ->assertHasNoErrors()
        // The form opens again on Furniture, not on the line it used to start on.
        ->assertSet('newLine', 'furniture')
        // A line that is not sold cannot be sent by hand either.
        ->set('newName', 'Second Person')
        ->set('newPhone', '0300 7654321')
        ->set('newLine', 'interior')
        ->call('createLead')
        ->assertHasErrors('newLine');

    expect(Lead::query()->pluck('business_line')->all())->toBe([BusinessLine::Furniture]);

    // (The factory writes a telephone number at random, and now and then one the validator refuses.)
    $contact = Contact::factory()->create(['phone' => '+923224000768', 'whatsapp' => '+923224000768']);
    Livewire::test(ContactShow::class, ['contact' => $contact])
        ->assertSet('leadLine', 'furniture')
        ->set('showLead', true)
        ->assertDontSee('Business line')
        ->call('createLead')
        ->assertHasNoErrors();

    expect($contact->leads()->sole()->business_line)->toBe(BusinessLine::Furniture);
});

it('takes an enquiry from the website as a Furniture one, and refuses a line that is not sold', function () {
    sellFurnitureAlone();

    // The older address took any line and filed an enquiry without one under Interior.
    $this->postJson('/api/public/leads', ['name' => 'Bilal Ahmed', 'email' => 'bilal@example.test'])->assertCreated();
    expect(Lead::query()->sole()->business_line)->toBe(BusinessLine::Furniture);

    foreach (['interior', 'project'] as $line) {
        $this->postJson('/api/public/leads', ['name' => 'Second Person', 'email' => 'second@example.test', 'business_line' => $line])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('business_line');
    }
    expect(Lead::query()->count())->toBe(1);
});

it('starts a quotation on Furniture without a brand to choose', function () {
    sellFurnitureAlone();
    $sales = $this->userWithRole('sales_manager');
    $this->actingAs($sales);
    $contact = Contact::factory()->create(['phone' => '+923224000768', 'whatsapp' => '+923224000768']);

    Livewire::test(QuoteCreate::class)
        ->assertSet('line', 'furniture')
        ->assertDontSee('Choose your Woodex brand')
        ->assertDontSee('Renovation')
        // The other brand cannot be chosen by hand, and cannot be sent as the line.
        ->call('chooseBrand', 'interior')
        ->assertSet('line', 'furniture')
        ->call('selectContact', $contact->id)
        ->call('chooseTemplate', 0)
        ->set('line', 'interior')
        ->call('create')
        ->assertHasErrors('line')
        ->set('line', 'furniture')
        ->call('create')
        ->assertHasNoErrors();

    expect(Quote::query()->sole()->business_line)->toBe(BusinessLine::Furniture);

    // An enquiry kept from another line does not carry its line into a new quotation.
    $older = Lead::factory()->for($contact)->create(['owner_id' => $sales->id, 'business_line' => 'interior']);
    Livewire::withQueryParams(['lead' => $older->id])->test(QuoteCreate::class)->assertSet('line', 'furniture');
});

it('starts an invoice, a repeating invoice and the document design on Furniture', function () {
    sellFurnitureAlone();
    $this->actingAs($this->userWithRole('owner'));

    Livewire::test(InvoiceCreate::class)
        ->assertSet('line', 'furniture')
        // Starting on Furniture is the same as choosing it: the first row counts pieces, not jobs.
        ->assertSet('items.0.unit', 'nos')
        ->assertDontSee('Brand / line')
        ->assertDontSee('(WI)');

    Livewire::test(RecurringInvoices::class)->assertSet('businessLine', 'furniture')->set('showForm', true)->assertDontSee('Business line');

    Livewire::test(DocumentDesigner::class)
        ->assertSet('line', 'furniture')
        ->assertSet('loadedLine', 'furniture')
        ->assertDontSee('Woodex Interior')
        // The other brand's design is not opened by naming it.
        ->set('line', 'interior')
        ->assertStatus(422);
});

it('neither offers nor installs the Interior starter templates', function () {
    sellFurnitureAlone();
    $this->actingAs($this->userWithRole('owner'));

    Livewire::test(QuoteTemplates::class)
        ->assertDontSee('Import Woodex starter templates')
        ->call('importStarters')
        ->assertNotFound();

    expect(DB::table('quote_templates')->count())->toBe(0);

    // Where the Interior brand is sold, the pack is offered as before.
    config(['woodex.business_lines' => ['interior', 'furniture']]);
    Livewire::test(QuoteTemplates::class)->assertSee('Import Woodex starter templates');
});

it('keeps the description library to the brand on sale', function () {
    sellFurnitureAlone();
    $this->actingAs($this->userWithRole('owner'));

    // The table is created with starter text for both brands (migration 2026_10_17_120000).
    $interior = DescriptionTemplate::query()->where('business_line', 'interior')->firstOrFail();
    $furniture = DescriptionTemplate::query()->where('business_line', 'furniture')->firstOrFail();

    Livewire::test(DescriptionLibrary::class)
        ->assertSee('Executive table specification')
        ->assertDontSee('Kitchen joinery')
        ->assertDontSee('Architectural services')
        ->assertDontSee('Both brands')
        // Naming the other brand in the address lists nothing of it.
        ->set('line', 'interior')
        ->assertDontSee('Kitchen joinery')
        ->set('line', '')
        ->call('openEdit', $furniture->id)
        ->assertSet('editingId', $furniture->id)
        ->assertDontSee('Interior / Project')
        ->call('openEdit', $interior->id)
        ->assertNotFound();

    // Nor does it prefill a new rate-book item.
    Livewire::withQueryParams(['description' => $interior->id])->test(RateBook::class)->assertNotFound();
    Livewire::withQueryParams(['description' => $furniture->id])->test(RateBook::class)->assertOk()->assertSet('formLine', 'furniture');

    // Where both brands are sold the library is whole again.
    config(['woodex.business_lines' => ['interior', 'furniture', 'project']]);
    Livewire::test(DescriptionLibrary::class)->assertSee('Kitchen joinery')->assertSee('Executive table specification')->assertSee('Both brands');
});

it('greets a client in the name the settings give, on every screen that writes a WhatsApp message', function () {
    sellFurnitureAlone();
    $sales = $this->userWithRole('sales_manager', ['name' => 'Sara Malik']);
    $this->actingAs($sales);
    $contact = Contact::factory()->create(['name' => 'Hina Qureshi', 'phone' => '+923001234567', 'whatsapp' => '+923001234567']);
    $lead = Lead::factory()->for($contact)->create(['owner_id' => $sales->id, 'business_line' => 'furniture', 'service' => 'office-chairs', 'message' => 'Twelve chairs for the head office.']);
    $quote = app(CreateQuote::class)->handle($contact, BusinessLine::Furniture, $sales, $lead, null, 'Head office');

    // A booked visit puts the lead on the visits list; a follow-up that is overdue puts it on the tracking board.
    app(ScheduleSiteVisit::class)->handle($lead, now()->addDay(), 60, null, $sales, $sales);
    $lead->refresh()->forceFill(['next_action_at' => now()->subDay(), 'first_response_at' => now()->subDays(2)])->save();

    $leadPage = pageWords((string) $this->get(route('admin.leads.show', $lead))->assertOk()->getContent());
    $contactPage = pageWords((string) $this->get(route('admin.contacts.show', $contact))->assertOk()->getContent());
    $quotePage = pageWords((string) $this->get(route('admin.quotes.show', $quote))->assertOk()->getContent());
    $boardPage = pageWords((string) $this->get(route('admin.leads.index', ['tab' => 'tracking']))->assertOk()->getContent());
    $visitsPage = pageWords((string) $this->get(route('admin.tasks.index', ['tab' => 'visits']))->assertOk()->getContent());

    // Each of the five wrote "Woodex Interior" itself, whatever the settings said.
    expect($leadPage)->toContain("this is Sara Malik from Woodex Furniture regarding your enquiry ({$lead->ref}).")
        ->and($contactPage)->toContain('this is Sara Malik from Woodex Furniture.')
        ->and($quotePage)->toContain("this is regarding your quotation {$quote->label()} from Woodex Furniture.")
        ->and($boardPage)->toContain("this is Sara Malik from Woodex Furniture regarding your enquiry ({$lead->ref}).")
        ->and($visitsPage)->toContain('this is Woodex Furniture confirming your site visit on ')
        ->and($leadPage.$contactPage.$quotePage.$boardPage.$visitsPage)->not->toContain('Interior');

    // The name is the owner's to set (System → Settings); the screens follow it.
    $business = app(BusinessSettings::class);
    $business->furniture_brand_name = 'Woodex Office Furniture';
    $business->save();

    expect(pageWords((string) $this->get(route('admin.leads.show', $lead))->getContent()))
        ->toContain('from Woodex Office Furniture regarding your enquiry');
});

it('takes the brand of a line from the settings, and falls back rather than say nothing', function () {
    $business = app(BusinessSettings::class);
    $business->brand_name = 'Woodex Group';
    $business->furniture_brand_name = 'Woodex Furniture';
    $business->save();

    config(['woodex.business_lines' => ['furniture']]);
    expect(Brand::name())->toBe('Woodex Furniture')
        ->and(Brand::name(BusinessLine::Project))->toBe('Woodex Group')
        ->and(Brand::wordmark())->toBe('FURNITURE')
        ->and(Brand::wordmark(BusinessLine::Interior))->toBe('INTERIOR STUDIO');

    $business->furniture_brand_name = '  ';
    $business->save();
    expect(Brand::name())->toBe('Woodex Group');

    $business->brand_name = '';
    $business->save();
    expect(Brand::name())->toBe('Woodex');
});

it('prints the Furniture name, and nothing of Interior, on every page a client opens', function () {
    config(['woodex.business_lines' => ['furniture']]);
    Storage::fake('local');
    Notification::fake();
    Mail::fake();

    $owner = $this->userWithRole('owner');
    $contact = Contact::factory()->create(['name' => 'Hina Qureshi', 'email' => 'hina@example.com', 'phone' => '+923001234567', 'whatsapp' => '+923001234567']);
    $quote = app(CreateQuote::class)->handle($contact, BusinessLine::Furniture, $owner, title: 'Office furniture');
    app(QuoteEditor::class)->updateLine($quote->items()->firstOrFail(), [
        'product_name' => 'Executive workstation', 'description' => 'Powder-coated frame.', 'unit' => 'nos', 'qty' => '1', 'rate_minor' => 49000000,
    ]);
    $quote = app(SendQuote::class)->handle($quote->refresh(), $owner, 'link', false)->quote->refresh();

    // The four pages a client is sent to, each for a Furniture record. Every one of them printed
    // "INTERIOR STUDIO" under the mark: three whatever the record was, the quotation for any line but Furniture.
    $pages = ['the quotation' => (string) $this->get(QuoteLinks::signed('quotes.portal', $quote))->assertOk()->getContent()];

    $invoice = app(CreateFurnitureInvoice::class)->handle($quote, $owner);
    app(IssueInvoice::class)->handle($invoice, $owner);
    $pages['the invoice'] = (string) $this->get(InvoiceLinks::signed('invoices.portal', $invoice->refresh()))->assertOk()->getContent();

    app(AcceptQuote::class)->handle($quote, 'Hina Qureshi', null, '127.0.0.1', 'PHPUnit');
    $project = $quote->refresh()->project;
    $pages['the project'] = (string) $this->get(PortalLinks::signed('projects.portal', $project))->assertOk()->getContent();

    $pages['the code sign-in'] = (string) $this->get('/portal')->assertOk()->getContent();

    $wrong = [];
    foreach ($pages as $name => $html) {
        $words = pageWords($html);
        // A quotation of the current design carries the name of its own document design in place of the mark.
        $mark = $name === 'the quotation' ? '<span class="font-heading text-xl">Woodex Furniture</span>' : '>FURNITURE</small>';
        if (! str_contains($words, $mark)) {
            $wrong[] = "{$name}: the Furniture name is not in the header";
        }
        if (stripos($words, 'interior') !== false) {
            $wrong[] = "{$name}: names Interior";
        }
    }
    expect($wrong)->toBe([]);

    // A quotation frozen before the current design falls back to the mark, and one frozen without a line
    // (the oldest ones) took the Interior words. Both now read FURNITURE here. A version is never rewritten by
    // the application, so the older shapes are written straight into the table.
    $version = $quote->versions()->reorder('revision', 'desc')->firstOrFail();
    foreach ([['furniture_revision' => 4], ['furniture_revision' => 4, 'business_line' => null]] as $older) {
        DB::table('quote_versions')->where('id', $version->id)->update(['snapshot' => json_encode(array_replace($version->snapshot, $older))]);

        $this->get(QuoteLinks::signed('quotes.portal', $quote))->assertOk()
            ->assertSee('>FURNITURE</small>', false)
            ->assertDontSee('INTERIOR STUDIO');
    }

    // A record kept from the Interior line still carries its own mark: nothing is relabelled unseen.
    expect(Brand::wordmark($project->business_line))->toBe('FURNITURE')
        ->and(Brand::wordmark(BusinessLine::Project))->toBe('INTERIOR STUDIO');
});

it('does not ship the holding page of the Interior studio', function () {
    // resources/views/site/holding.blade.php was the Interior studio's "website on its way" page: its headline,
    // its services and its street address. No route and no code rendered it; it is removed, not adapted.
    expect(view()->exists('site.holding'))->toBeFalse();

    foreach (Route::getRoutes() as $route) {
        expect((string) $route->getActionName())->not->toContain('Holding');
    }
});

it('invites a new member of staff to the workspace of the brand, not of the Interior studio', function () {
    config(['woodex.business_lines' => ['furniture']]);
    $user = $this->userWithRole('sales');

    $lines = (new StaffInvitation('token'))->toMail($user)->introLines;

    expect(implode(' ', $lines))->toContain('the Woodex Furniture workspace for sales and projects')
        ->not->toContain('Interior');
});

it('seeds a revenue account only for a brand that is sold', function () {
    DB::table('chart_of_accounts')->whereIn('code', ['4010', '4020', '4090'])->delete();

    config(['woodex.business_lines' => ['furniture']]);
    $this->seed(ChartOfAccountsSeeder::class);

    expect(DB::table('chart_of_accounts')->whereIn('code', ['4010', '4020', '4090'])->orderBy('code')->pluck('name', 'code')->all())
        ->toBe(['4020' => 'Furniture revenue', '4090' => 'Other income']);

    // Selling the Interior brand as well brings its account, and seeding twice adds nothing.
    config(['woodex.business_lines' => ['interior', 'furniture']]);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);

    expect(DB::table('chart_of_accounts')->whereIn('code', ['4010', '4020', '4090'])->count())->toBe(3);
});

it('still says what an older record is, and lets it be moved to the line on sale', function () {
    sellFurnitureAlone();
    $sales = $this->userWithRole('sales_manager');
    $this->actingAs($sales);
    $lead = Lead::factory()->create(['owner_id' => $sales->id, 'business_line' => 'interior', 'service' => null, 'message' => 'Shelving for the showroom.']);

    // Nothing changes a record unseen: the page names the line it has…
    $this->get(route('admin.leads.show', $lead))->assertOk()->assertSee('Interior');

    // …its own line stays a choice beside the one on sale, and a third line is not accepted.
    Livewire::test(LeadShow::class, ['lead' => $lead])
        ->call('editDetails')
        ->assertSee('Business line')
        ->set('details.business_line', 'project')
        ->call('saveDetails')
        ->assertHasErrors('details.business_line')
        ->set('details.business_line', 'furniture')
        ->call('saveDetails')
        ->assertHasNoErrors();

    expect($lead->refresh()->business_line)->toBe(BusinessLine::Furniture);
    $this->get(route('admin.leads.show', $lead))->assertOk()->assertDontSee('Interior');
});

it('splits a report by line only where that tells something', function () {
    sellFurnitureAlone();
    $owner = $this->userWithRole('owner');
    $this->actingAs($owner);
    $contact = Contact::factory()->create(['phone' => '+923224000768', 'whatsapp' => '+923224000768']);
    Lead::factory()->for($contact)->create(['owner_id' => $owner->id, 'business_line' => 'furniture', 'service' => 'office-chairs', 'message' => 'Twelve chairs for the head office.']);

    // One line on sale: a table "By business line" would repeat the totals in one row.
    $this->get('/admin/reports?tab=funnel')->assertOk()->assertDontSee('By business line')->assertSee('by source and owner');
    $this->get('/admin/reports?tab=revenue')->assertOk()->assertDontSee('By business line')->assertSee('per month and per bank');

    // A record of a line that is no longer sold is not folded into Furniture: the split comes back.
    // (The factory dates a lead up to twenty days back, which can fall outside the period of the report.)
    Lead::factory()->for($contact)->create(['owner_id' => $owner->id, 'business_line' => 'project', 'service' => null, 'message' => 'Shelving for the showroom.', 'created_at' => now(), 'updated_at' => now()]);
    $this->get('/admin/reports?tab=funnel')->assertOk()->assertSee('By business line');

    // And where several lines are sold it is there from the start.
    config(['woodex.business_lines' => ['interior', 'furniture', 'project']]);
    $this->get('/admin/reports?tab=revenue')->assertOk()->assertSee('By business line')->assertSee('per month, per line, per bank');
});
