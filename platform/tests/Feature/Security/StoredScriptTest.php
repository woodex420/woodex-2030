<?php

declare(strict_types=1);

use App\Domain\Commerce\Services\CategoryContent;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Projects\Models\Project;
use App\Domain\Sales\Actions\AcceptQuote;
use App\Domain\Sales\Actions\CreateFurnitureInvoice;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Enums\AcceptanceChannel;
use App\Domain\Sales\Services\InvoiceLinks;
use App\Domain\Sales\Services\InvoicePdf;
use App\Domain\Sales\Services\InvoiceSnapshot;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuoteLinks;
use App\Domain\Sales\Services\QuotePdf;
use App\Domain\Sales\Services\QuoteSnapshot;
use App\Livewire\Admin\Crm\LeadShow;
use App\Support\AdminNavigation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Js;
use Livewire\Livewire;
use Symfony\Component\Finder\Finder;

/*
| Stored script (document 10 §4; master list T-160): text that somebody typed — a visitor into the public enquiry
| form, a member of staff into a note or a quotation — is shown to other people later, and must be shown as text.
|
| One enquiry is sent through the public form with script in every field, and a sale is built on it with script
| in every text a member of staff types. Then every screen of the menu, the record's own pages, the documents
| and the client's pages are drawn, and none may carry the script in a form a browser would run:
|
|   - as a tag (<script>, or a tag with an event handler);
|   - inside an attribute that the browser, Alpine or Livewire runs as script (there the HTML escaping is undone
|     before the script is read, so escaping alone does not protect).
|
| The last two tests keep the list of places where a view prints something unescaped, so that a new one is a
| decision.
*/

const TAG = '<script>window.hacked=1</script>';
const HANDLER = '<img src=x onerror="window.hacked=1">';
const BREAK_OUT = "x'); window.hacked=1; ('x\"); window.hacked=1; (\"";

function hostile(string $before = ''): string
{
    return $before.TAG.HANDLER.BREAK_OUT;
}

/** The ways the hostile text could be live in a page. An empty list means it is shown as text, or not at all. */
function liveScriptIn(string $html): array
{
    $found = [];

    if (str_contains($html, '<script>window.hacked')) {
        $found[] = 'a script tag';
    }
    if (preg_match('/<[a-z][^<>]*\son[a-z]+\s*=\s*["\']?window\.hacked/i', $html) === 1) {
        $found[] = 'an event handler';
    }

    // Attributes whose value is run as script: Alpine's (x-…, @…, :…), Livewire's actions, and the browser's own.
    // The browser undoes the HTML escaping before the script is read, so the value is looked at as the script
    // will see it. Text that @js(…) wrote stays inside its quotes (its quotes are written as \u0027 and \u0022);
    // text that broke out of a quoted word, or was never in one, is found here.
    preg_match_all('/\s(x-[\w:.\-]+|@[\w.\-]+|:[\w\-]+|wire:(?:click|submit|change|input|keydown|keyup|blur|focus|init|poll|mouseenter|mouseleave|dblclick)[\w.\-]*|on[a-z]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $html, $attributes, PREG_SET_ORDER);

    foreach ($attributes as $attribute) {
        $script = html_entity_decode($attribute[2] !== '' ? $attribute[2] : ($attribute[3] ?? ''), ENT_QUOTES | ENT_HTML5);

        if (str_contains($script, "'); window.hacked=1; ('") || str_contains($script, '"); window.hacked=1; ("') || str_contains($script, '<script>window.hacked')) {
            $found[] = 'the attribute '.$attribute[1];
        }
    }

    return array_values(array_unique($found));
}

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    Mail::fake();
    config(['modules.enabled' => 'lead_api,client_portal', 'woodex.business_lines' => ['furniture']]);

    $this->owner = $this->userWithRole('owner');
});

it('shows as text, on every screen and document, what a visitor and the staff typed', function () {
    // 1. A visitor: the public enquiry form, script in every field it takes as text.
    $this->postJson('/api/public/leads', [
        'name' => hostile('Ayesha '), 'phone' => '0322-4000768', 'email' => 'ayesha@example.com',
        'company' => hostile('Co '), 'city' => mb_substr(hostile(), 0, 80), 'area' => hostile(), 'service' => mb_substr(TAG, 0, 60),
        'message' => hostile('Twelve workstations. '), 'form' => mb_substr(TAG, 0, 80),
        'page_url' => 'https://woodex.com.pk/?q='.TAG, 'referrer' => 'https://example.com/"><script>window.hacked=1</script>',
        'utm_source' => HANDLER, 'utm_campaign' => TAG, 'consent' => true,
    ])->assertCreated();

    $lead = Lead::query()->sole();
    $lead->forceFill(['owner_id' => $this->owner->id, 'business_line' => 'furniture', 'created_at' => now()->subDay()])->save();

    // 2. The staff: a note, and a quotation with script in each text field, sent, accepted and invoiced.
    Livewire::actingAs($this->owner)->test(LeadShow::class, ['lead' => $lead])
        ->set('logType', 'note')->set('logBody', hostile('Called @owner. '))->call('logActivity')->assertHasNoErrors();

    $quote = app(CreateQuote::class)->handle($lead->contact, BusinessLine::Furniture, $this->owner, $lead, null, hostile('Office '));
    app(QuoteEditor::class)->updateLine($quote->items()->firstOrFail(), [
        'product_name' => hostile('Workstation '), 'description' => hostile('Laminate top. '), 'unit' => 'nos', 'qty' => '12', 'rate_minor' => 4_500_000,
    ]);
    DB::table('quotes')->where('id', $quote->id)->update([
        'notes' => hostile('Note '), 'intro_line' => hostile('Intro '), 'site' => hostile('Site '),
        // Rich text is stored as HTML. This is what an import, or a fault, could put there.
        'scope_html' => '<p>Scope</p>'.TAG.HANDLER.'<a href="javascript:window.hacked=1">x</a><p onclick="window.hacked=1">y</p>',
    ]);
    app(SendQuote::class)->handle($quote->refresh(), $this->owner, 'link', false);
    app(AcceptQuote::class)->handle($quote->refresh(), hostile('Ayesha '), null, null, null, $this->owner, null, AcceptanceChannel::Phone);
    $invoice = app(IssueInvoice::class)->handle(app(CreateFurnitureInvoice::class)->handle($quote->refresh(), $this->owner), $this->owner);
    $project = Project::query()->where('quote_id', $quote->id)->sole();

    // 3. Every place it is shown.
    $this->actingAs($this->owner);
    $pages = [];

    foreach (collect(AdminNavigation::for($this->owner))->flatMap(fn (array $group): array => $group['items']) as $item) {
        $pages['menu: '.$item['label']] = $this->followingRedirects()->get(route($item['route']))->assertOk()->getContent();
    }
    foreach ([
        'the lead' => route('admin.leads.show', $lead),
        'the contact' => route('admin.contacts.show', $lead->contact),
        'the quotation' => route('admin.quotes.show', $quote),
        'the invoice' => route('admin.invoices.show', $invoice),
        'the project' => route('admin.projects.show', $project),
    ] as $what => $address) {
        $pages[$what] = $this->get($address)->assertOk()->getContent();
    }

    $pages['the quotation as a document'] = app(QuotePdf::class)->html(app(QuoteSnapshot::class)->build($quote->refresh()));
    $pages['the invoice as a document'] = app(InvoicePdf::class)->html(app(InvoiceSnapshot::class)->build($invoice->refresh()));

    auth()->logout();
    $pages['the client\'s page of the quotation'] = $this->get(QuoteLinks::signed('quotes.portal', $quote))->assertOk()->getContent();
    $pages['the client\'s page of the invoice'] = $this->get(InvoiceLinks::signed('invoices.portal', $invoice))->assertOk()->getContent();

    $live = [];
    $shown = 0;

    foreach ($pages as $what => $html) {
        $shown += str_contains($html, 'window.hacked') ? 1 : 0;

        foreach (liveScriptIn($html) as $how) {
            $live[] = "{$what}: {$how}";
        }
    }

    // The text really was on those pages (a test that drew pages without it would prove nothing)…
    expect($shown)->toBeGreaterThan(8)
        // …and nowhere in a form a browser runs.
        ->and($live)->toBe([]);
});

it('finds script when it is live: the check itself is checked', function () {
    expect(liveScriptIn('<p>'.e(hostile()).'</p>'))->toBe([])
        ->and(liveScriptIn('<p>'.TAG.'</p>'))->toBe(['a script tag'])
        ->and(liveScriptIn('<p>'.HANDLER.'</p>'))->toBe(['an event handler'])
        // The same text written by @js(…) stays a quoted word, and is not found.
        ->and(liveScriptIn('<a :href="share('.e((string) Js::from(hostile())).')">x</a>'))->toBe([])
        ->and(liveScriptIn("<div x-data='{ \"name\": \"".e(BREAK_OUT)."\" }'></div>"))->toBe(['the attribute x-data'])
        ->and(liveScriptIn('<button wire:click="open(\''.e(BREAK_OUT).'\')">x</button>'))->toBe(['the attribute wire:click'])
        ->and(liveScriptIn('<div x-data="{ name: \''.e(BREAK_OUT).'\' }"></div>'))->toBe(['the attribute x-data'])
        ->and(liveScriptIn('<button @click="go(\''.e(BREAK_OUT).'\')">x</button>'))->toBe(['the attribute @click'])
        // Text for a person, not for the browser: a title, a question before an action, the state Livewire carries.
        ->and(liveScriptIn('<button title="'.e(hostile()).'" wire:confirm="Delete '.e(hostile()).'?" wire:snapshot="'.e(json_encode(['name' => hostile()])).'">x</button>'))->toBe([]);
});

it('keeps formatted catalogue text to plain formatting', function () {
    $html = app(CategoryContent::class)->formattedText(
        "A **strong** desk.\n\n".TAG."\n\n".HANDLER."\n\n[a link](javascript:window.hacked=1) and [a good one](https://woodexfurniture.pk)\n\n<a href=\"https://x.example\" onclick=\"window.hacked=1\">raw</a>",
        'markdown',
    );

    expect(liveScriptIn($html))->toBe([])
        ->and($html)->toContain('<strong>strong</strong>')->toContain('href="https://woodexfurniture.pk"')
        ->not->toContain('javascript:')->not->toContain('<img')->not->toContain('onclick');
});

it('prints unescaped only in the places that were looked at', function () {
    // {!! … !!} prints as it is. Each place below prints something that is made safe before it gets there:
    // sanitised rich text (App\Support\Html), text escaped by hand and then given line breaks or highlights, an
    // image the application drew itself, or a value encoded for script. A new place must be looked at and added.
    $looked = [
        'admin/website/builder.blade.php' => 1,
        'commerce/category-content-blocks.blade.php' => 2,
        'commerce/product-preview.blade.php' => 2,
        'components/admin/content-editor.blade.php' => 1,
        'components/admin/header.blade.php' => 1,
        'components/icon.blade.php' => 1,
        'livewire/admin/crm/contact-show.blade.php' => 1,
        'livewire/admin/crm/lead-show.blade.php' => 1,
        'livewire/admin/profile/security.blade.php' => 2,
        'pdf/furniture-document-v1.blade.php' => 1,
        'pdf/furniture-document-v2.blade.php' => 1,
        'pdf/furniture-quotation-v3.blade.php' => 1,
        'pdf/furniture-quotation-v4.blade.php' => 1,
        'pdf/partials/furniture-v5/scope.blade.php' => 1,
        'pdf/partials/interior-v2/boq.blade.php' => 1,
        'pdf/partials/quotation-v21-rows.blade.php' => 1,
        'pdf/partials/quote-blocks/boq.blade.php' => 1,
        'pdf/partials/quote-blocks/notes.blade.php' => 1,
        'pdf/partials/quote-blocks/scope.blade.php' => 1,
        'site/quote.blade.php' => 2,
        'site/tags.blade.php' => 4,
    ];
    $found = [];

    foreach ((new Finder)->files()->in(resource_path('views'))->name('*.blade.php')->sortByName() as $file) {
        $count = substr_count($file->getContents(), '{!!');

        if ($count > 0) {
            $found[str_replace('\\', '/', $file->getRelativePathname())] = $count;
        }
    }
    ksort($found);
    ksort($looked);

    expect($found)->toBe($looked);
});

it('puts only numbers and fixed words into attributes that are run as script', function () {
    // Inside wire:click, x-data, @click and their kind, {{ … }} does not protect: the browser undoes the escaping
    // before the script is read. So only a record's number, a position, or a word from a fixed list may be
    // printed there. Anything a person typed goes through @js(…) in a plain element, or through a data- attribute.
    $allowed = '/^(?:'
        .'\(int\)\s*\$\w+'                                              // a number, forced
        .'|\$\w+(?:\[[^\]]+\])*->(?:id|value|key)'                        // a record's number, an enum's value, a stage's key
        .'|\$\w+\[\'(?:id|key|kind|value)\'\]'                            // the same from an array
        .'|\$(?:i|index|productIndex|groupKey|tabId|key|value|field|preset|kind|mode|name|submit|rule|brand|connectionKey)'  // a position, or a word of a fixed list
        .'|[^?{}]+ \? \'\w+\' : \'\w+\''                                    // one of two fixed words
        .'|\$key === \'total\' \? \'\' : \$key'
        .'|count\(\$form\[\'images\'\]\)|csrf_token\(\)|route\(\'admin\.quotes\.preview\', \$quote, false\)'
        .'|max\(0, \(int\) now\(\)->diffInSeconds\(\$telegram->link_code_expires_at, false\)\)'
        .'|\\\\Illuminate\\\\Support\\\\Str::singular\(\$tab\)'
        // Looked at one by one: a page\'s path, a theme\'s name and an import folder, each made by the application.
        .'|\$page\[\'path\'\]|\$preset\[\'name\'\]|\$report\[\'directory\'\]'
        // A product\'s number, and the number that stands for the code of its option (CatalogueProducts): both
        // whole numbers by construction. The code itself is typed text and is not printed there.
        .'|\$choice\[\'product_id\'\]|\$choice\[\'code_number\'\]'
        .')$/';
    $attribute = '/(?<![\w-])(x-data|x-init|x-on:[\w.\-]+|@[\w.\-]+|x-show|x-effect|x-text|x-html|x-bind:[\w\-]+|wire:(?:click|submit|change|input|keydown|keyup|blur|init|poll)[\w.\-]*|onclick|onchange|oninput)\s*=\s*"([^"]*)"/s';
    $directives = ['@if', '@foreach', '@endif', '@php', '@class', '@can', '@js', '@json', '@error', '@include', '@props', '@endforeach', '@forelse', '@unless', '@else', '@elseif', '@style', '@checked', '@selected', '@disabled'];
    $unknown = [];

    foreach ((new Finder)->files()->in(resource_path('views'))->name('*.blade.php')->sortByName() as $file) {
        preg_match_all($attribute, $file->getContents(), $matches, PREG_SET_ORDER);

        foreach ($matches as [, $name, $value]) {
            if (in_array($name, $directives, true)) {
                continue;
            }
            preg_match_all('/\{\{(.*?)\}\}/s', $value, $echoes);

            foreach ($echoes[1] as $echo) {
                if (preg_match($allowed, trim($echo)) !== 1) {
                    $unknown[] = str_replace('\\', '/', $file->getRelativePathname()).' '.$name.': {{ '.trim($echo).' }}';
                }
            }
        }
    }

    expect(array_values(array_unique($unknown)))->toBe([]);
});
