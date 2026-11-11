<?php

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\AcceptQuote;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\SaveDocumentDesign;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Data\QuoteBlueprint;
use App\Domain\Sales\Models\BankAccount;
use App\Domain\Sales\Models\DocumentDesign;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\QuoteTemplate;
use App\Domain\Sales\Services\DocumentStyles;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuotePdf;
use App\Domain\Sales\Services\QuoteSnapshot;
use App\Livewire\Admin\Sales\DocumentDesigner;
use App\Livewire\Admin\Sales\QuoteBuilder;
use App\Livewire\Admin\Sales\QuoteTemplates;
use App\Settings\SalesSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    $this->owner = $this->userWithRole('owner');
    $this->actingAs($this->owner);
    $this->lead = Lead::factory()->create(['owner_id' => $this->owner->id]);
    $this->quote = app(CreateQuote::class)->handle($this->lead->contact, BusinessLine::Interior, $this->owner, $this->lead);
    app(QuoteEditor::class)->updateLine($this->quote->items()->firstOrFail(), ['description' => 'Reviewed joinery', 'rate_minor' => 100000]);
    $this->quote->refresh();
    $this->designValues = ['brand_name' => 'Woodex Interior', 'accent_color' => '#234567', 'header_text' => 'Reviewed design identity', 'footer_text' => 'Thank you for your business'];
});

it('normalizes block lists: unknown dropped, duplicates once, missing join at catalogue position, locked visible', function () {
    $blocks = DocumentStyles::blocks('quote', [
        ['key' => 'terms', 'visible' => true],
        ['key' => 'boq', 'visible' => false],
        ['key' => 'bogus', 'visible' => true],
        ['key' => 'terms', 'visible' => false],
    ]);
    $keys = collect($blocks)->pluck('key');

    expect($blocks)->toHaveCount(9)
        ->and($blocks[0])->toBe(['key' => 'scope', 'visible' => true])
        ->and($keys->search('terms'))->toBeLessThan($keys->search('boq'))
        ->and(collect($blocks)->firstWhere('key', 'boq')['visible'])->toBeTrue()
        ->and(collect($blocks)->pluck('key')->duplicates())->toBeEmpty()
        ->and(DocumentStyles::visibleBlocks('quote', null))->toBe(array_keys(DocumentStyles::QUOTE_BLOCKS))
        ->and(DocumentStyles::visibleBlocks('invoice', []))->toBe(array_keys(DocumentStyles::INVOICE_BLOCKS))
        ->and(DocumentStyles::theme('nope'))->toBe('classic')
        ->and(DocumentStyles::theme('premium'))->toBe('premium');
});

it('persists theme and blocks through the action', function () {
    $design = app(SaveDocumentDesign::class)->handle($this->owner, 'interior', [...$this->designValues,
        'pdf_theme' => 'premium',
        'quote_blocks' => [['key' => 'terms', 'visible' => true]],
        'invoice_blocks' => [['key' => 'notes', 'visible' => false]],
    ], 1);

    expect($design->pdf_theme)->toBe('premium')
        ->and($design->quote_blocks)->toHaveCount(9)
        ->and($design->quote_blocks[0]['key'])->toBe('scope')
        ->and(collect($design->quote_blocks)->firstWhere('key', 'terms')['visible'])->toBeTrue()
        ->and(collect($design->invoice_blocks)->firstWhere('key', 'notes')['visible'])->toBeFalse()
        ->and(app(DocumentStyles::class)->copy(BusinessLine::Interior)['pdf_theme'])->toBe('premium');
});

it('rejects unknown block keys and themes in the action', function (array $extra) {
    app(SaveDocumentDesign::class)->handle($this->owner, 'interior', [...$this->designValues, ...$extra], 1);
})->with([
    [['quote_blocks' => [['key' => 'nope', 'visible' => true]]]],
    [['invoice_blocks' => [['key' => 'scope', 'visible' => true]]]],
    [['pdf_theme' => 'neon']],
])->throws(ValidationException::class);

it('renders new quotes with reordered blocks, hidden blocks omitted and the chosen theme', function () {
    app(SaveDocumentDesign::class)->handle($this->owner, 'interior', [...$this->designValues,
        'pdf_theme' => 'premium',
        'quote_blocks' => [
            ['key' => 'terms', 'visible' => true],
            ['key' => 'scope', 'visible' => false],
        ],
    ], 1);

    $quote = app(CreateQuote::class)->handle($this->lead->contact, BusinessLine::Interior, $this->owner, $this->lead);
    app(QuoteEditor::class)->updateLine($quote->items()->firstOrFail(), ['description' => 'Walk-in wardrobe', 'rate_minor' => 250000]);
    app(QuoteEditor::class)->updateDetails($quote, ['scope_html' => '<p>Scope marker text</p>', 'terms' => "First term line\nSecond term line"]);

    $snapshot = app(QuoteSnapshot::class)->build($quote->refresh());
    $html = view('pdf.quote', ['s' => $snapshot, 'draft' => true])->render();

    expect($snapshot['document_design']['pdf_theme'])->toBe('premium')
        ->and($html)->toContain('/* theme: premium */')
        ->and(strpos($html, 'Terms and conditions'))->toBeLessThan(strpos($html, '<h2>Detailed bill of quantities</h2>'))
        ->and($html)->not->toContain('Scope of work')
        ->and($html)->not->toContain('Scope marker text')
        ->and($html)->toContain('First term line')
        ->and(app(QuotePdf::class)->render($snapshot, draft: true))->toStartWith('%PDF-');
});

it('renders legacy snapshots without theme or block keys in the original order', function () {
    app(QuoteEditor::class)->updateDetails($this->quote, ['scope_html' => '<p>Scope marker text</p>', 'terms' => 'First term line']);
    $snapshot = app(QuoteSnapshot::class)->build($this->quote->refresh());
    unset($snapshot['quotation_renderer'], $snapshot['quote_layout']);
    $snapshot['document_design'] = array_diff_key($snapshot['document_design'], array_flip(['pdf_theme', 'quote_blocks', 'invoice_blocks']));

    $html = view('pdf.quote', ['s' => $snapshot, 'draft' => true])->render();

    expect(strpos($html, 'Scope of work'))->toBeLessThan(strpos($html, '<h2>Detailed bill of quantities</h2>'))
        ->and(strpos($html, '<h2>Detailed bill of quantities</h2>'))->toBeLessThan(strpos($html, 'Terms &amp; conditions'))
        ->and($html)->toContain('Scope marker text')
        ->and($html)->not->toContain('/* theme:');
});

it('keeps a sent quote on its frozen design after the master changes', function () {
    $version = app(SendQuote::class)->handle($this->quote, $this->owner);
    $before = view('pdf.quote', ['s' => $version->snapshot, 'draft' => false])->render();

    app(SaveDocumentDesign::class)->handle($this->owner, 'interior', [...$this->designValues,
        'pdf_theme' => 'minimal',
        'quote_blocks' => [['key' => 'signatures', 'visible' => false]],
    ], 1);

    $after = view('pdf.quote', ['s' => $version->fresh()->snapshot, 'draft' => false])->render();

    expect($after)->toBe($before)
        ->and(app(DocumentStyles::class)->copy(BusinessLine::Interior)['pdf_theme'])->toBe('minimal');
});

it('renders invoices with hidden and reordered blocks', function () {
    app(SaveDocumentDesign::class)->handle($this->owner, 'interior', [...$this->designValues,
        'invoice_blocks' => [
            ['key' => 'totals', 'visible' => true],
            ['key' => 'items', 'visible' => true],
            ['key' => 'payment_details', 'visible' => false],
        ],
    ], 1);

    BankAccount::query()->create(['business_line' => BusinessLine::Interior, 'title' => 'WOODEX INTERIOR', 'bank_name' => 'Bank Alfalah', 'account_number' => '0000-0000-0000', 'iban' => 'PK00ALFA0000000000000000', 'is_default' => true, 'is_active' => true]);

    $quote = app(CreateQuote::class)->handle($this->lead->contact, BusinessLine::Interior, $this->owner, $this->lead);
    app(QuoteEditor::class)->updateLine($quote->items()->firstOrFail(), ['description' => 'Walk-in wardrobe', 'rate_minor' => 250000]);
    app(SendQuote::class)->handle($quote->refresh(), $this->owner);
    app(AcceptQuote::class)->handle($quote->refresh(), 'Preview Client', null, '127.0.0.1', 'test');
    $invoice = Invoice::query()->where('quote_id', $quote->id)->firstOrFail();

    expect($invoice->snapshot['bank'])->not->toBeEmpty();

    $html = view('pdf.invoice', ['s' => $invoice->snapshot])->render();
    expect($html)->not->toContain('Payment details')
        ->and(strpos($html, 'Balance due'))->toBeLessThan(strpos($html, 'Particulars'));

    $control = $invoice->snapshot;
    $control['document_design'] = array_diff_key($control['document_design'], array_flip(['pdf_theme', 'quote_blocks', 'invoice_blocks']));
    $controlHtml = view('pdf.invoice', ['s' => $control])->render();
    expect($controlHtml)->toContain('Payment details')
        ->and(strpos($controlHtml, 'Particulars'))->toBeLessThan(strpos($controlHtml, 'Balance due'));
});

it('saves theme and blocks from the designer screen', function () {
    Livewire::test(DocumentDesigner::class)
        ->assertSet('pdfTheme', 'classic')
        ->assertSet('quoteBlocks.0.key', 'scope')
        ->call('moveBlock', 'quote', 0, 1)
        ->assertSet('quoteBlocks.0.key', 'summary')
        ->set('quoteBlocks.1.visible', false)
        ->set('pdfTheme', 'minimal')
        ->call('save')->assertHasNoErrors();

    $design = DocumentDesign::query()->where('business_line', 'interior')->firstOrFail();

    expect($design->pdf_theme)->toBe('minimal')
        ->and($design->quote_blocks[0]['key'])->toBe('summary')
        ->and($design->quote_blocks[1])->toBe(['key' => 'scope', 'visible' => false]);
});

it('allows quotation price and totals to hide while preserving invoice requirements and reset order', function () {
    Livewire::test(DocumentDesigner::class)
        ->set('quoteBlocks.2.visible', false)
        ->set('quoteBlocks.3.visible', false)
        ->call('save')->assertHasNoErrors();

    $blocks = DocumentDesign::query()->where('business_line', 'interior')->firstOrFail()->quote_blocks;

    expect(collect($blocks)->firstWhere('key', 'boq')['visible'])->toBeFalse()
        ->and(collect($blocks)->firstWhere('key', 'totals')['visible'])->toBeFalse();

    Livewire::test(DocumentDesigner::class)
        ->call('moveBlock', 'invoice', 2, 1)->assertSet('invoiceBlocks.3.key', 'terms')
        ->call('resetBlocks', 'invoice')->assertSet('invoiceBlocks.0.key', 'items');
});

it('exports and imports a quote template file', function () {
    $template = QuoteTemplate::query()->where('is_active', true)->firstOrFail();
    $template->update(['blueprint' => [...$template->blueprint, 'presentation' => ['quote_layout' => 'project', 'pdf_theme' => 'premium', 'client' => 'Must not transfer', 'bank' => 'Must not transfer']]]);

    Livewire::test(QuoteTemplates::class)
        ->call('exportTemplate', $template->id)
        ->assertFileDownloaded($template->slug.'.woodex-template.json');

    $count = QuoteTemplate::query()->count();
    $payload = [
        'format' => 'woodex-quote-template',
        'version' => 1,
        'name' => $template->name,
        'description' => $template->description,
        'business_line' => $template->business_line->value,
        'kind' => $template->kind->value,
        'milestone_plan' => $template->milestone_plan->value,
        'terms' => $template->terms,
        'notes' => $template->notes,
        'scope_html' => $template->scope_html,
        'blueprint' => $template->blueprint,
    ];
    $file = UploadedFile::fake()->createWithContent('import.json', json_encode($payload));

    Livewire::test(QuoteTemplates::class)->set('importFile', $file)->call('importTemplate')->assertHasNoErrors();

    $imported = QuoteTemplate::query()->latest('id')->firstOrFail();

    expect(QuoteTemplate::query()->count())->toBe($count + 1)
        ->and($imported->slug)->toBe($template->slug.'-2')
        ->and($imported->name)->toBe($template->name)
        ->and(QuoteBlueprint::sections($imported->blueprint))->toEqual(QuoteBlueprint::sections($template->blueprint))
        ->and($imported->blueprint['presentation']['quote_layout'])->toBe('project')
        ->and($imported->blueprint['presentation']['pdf_theme'])->toBe('premium')
        ->and(array_values(array_diff(array_keys($imported->blueprint['presentation']), ['quote_layout', 'pdf_theme', 'quote_blocks'])))->toBe([])
        ->and(count($imported->blueprint['presentation']))->toBe(3);
});

it('refuses invalid template files and unlinks unknown catalog items', function () {
    $count = QuoteTemplate::query()->count();

    Livewire::test(QuoteTemplates::class)
        ->set('importFile', UploadedFile::fake()->createWithContent('bad.json', 'not json'))
        ->call('importTemplate')->assertHasErrors('importFile');

    Livewire::test(QuoteTemplates::class)
        ->set('importFile', UploadedFile::fake()->createWithContent('other.json', json_encode(['format' => 'other', 'version' => 1])))
        ->call('importTemplate')->assertHasErrors('importFile');

    expect(QuoteTemplate::query()->count())->toBe($count);

    $file = UploadedFile::fake()->createWithContent('import.json', json_encode([
        'format' => 'woodex-quote-template',
        'version' => 1,
        'name' => 'Imported shell',
        'business_line' => 'interior',
        'kind' => 'execution',
        'milestone_plan' => 'execution_50_40_10',
        'blueprint' => ['sections' => [['name' => 'Shell', 'note' => '', 'items' => [['description' => 'Wardrobe', 'unit' => 'nos', 'qty' => '2', 'rate_minor' => 50000, 'catalog_item_id' => 999999]]]]],
    ]));

    Livewire::test(QuoteTemplates::class)->set('importFile', $file)->call('importTemplate')->assertHasNoErrors();

    $imported = QuoteTemplate::query()->where('slug', 'imported-shell')->firstOrFail();

    expect(QuoteBlueprint::sections($imported->blueprint)[0]['items'][0]['catalog_item_id'])->toBeNull();
});

it('restricts template export and import to catalog managers', function () {
    $template = QuoteTemplate::query()->where('is_active', true)->firstOrFail();
    $sales = $this->userWithRole('sales');

    Livewire::actingAs($sales)->test(QuoteTemplates::class)->call('exportTemplate', $template->id)->assertForbidden();
    Livewire::actingAs($sales)->test(QuoteTemplates::class)
        ->set('importFile', UploadedFile::fake()->createWithContent('x.json', '{}'))
        ->call('importTemplate')->assertForbidden();
});

it('stores the signed-by pick on the quote and falls back to settings when cleared', function () {
    $options = app(SalesSettings::class)->signatories;
    expect(count($options))->toBeGreaterThan(1);

    $picked = ['name' => (string) $options[1]['name'], 'title' => (string) ($options[1]['title'] ?? '')];

    $component = Livewire::test(QuoteBuilder::class, ['quote' => $this->quote])->set('details.signed_by', '1');

    expect($this->quote->refresh()->signatories)->toBe([$picked])
        ->and(app(QuoteSnapshot::class)->build($this->quote)['signatories'])->toBe([$picked]);

    $component->set('details.signed_by', '');

    expect($this->quote->refresh()->signatories)->toBeNull()
        ->and(app(QuoteSnapshot::class)->build($this->quote)['signatories'])->toBe(array_values($options));
});
