<?php

declare(strict_types=1);

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\SaveQuoteAsTemplate;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Models\QuoteTemplate;
use App\Domain\Sales\Services\DocumentStyles;
use App\Domain\Sales\Services\FurnitureDocument;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuoteLinks;
use App\Domain\Sales\Services\QuotePdf;
use App\Domain\Sales\Services\QuoteSnapshot;
use App\Livewire\Admin\Sales\DocumentDesigner;
use App\Livewire\Admin\Sales\QuoteBuilder;
use App\Livewire\Admin\Sales\QuoteTemplates;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    $this->owner = $this->userWithRole('owner');
    $this->actingAs($this->owner);
    $this->contact = Contact::factory()->create(['name' => 'Commercial revision QA']);
    $this->editor = app(QuoteEditor::class);
    $this->quote = app(CreateQuote::class)->handle($this->contact, BusinessLine::Furniture, $this->owner);
    $this->editor->updateLine($this->quote->items()->firstOrFail(), ['product_name' => 'Revision desk', 'description' => 'Approved finish and installation', 'qty' => '1', 'unit' => 'nos', 'rate_minor' => 4750000]);
});

it('uses current quotation-only revisions and six authoritative Furniture terms', function () {
    $s = app(QuoteSnapshot::class)->build($this->quote->refresh());
    $terms = FurnitureDocument::commercialTerms($s);
    expect($s['furniture_revision'])->toBe(5)
        ->and($s['document_design']['quote_block_policy'])->toBe('flexible')
        ->and($this->quote->advance_bp)->toBe(7500)
        ->and($this->quote->valid_until->toDateString())->toBe(today()->addDays(12)->toDateString())
        ->and($s['totals']['advance_minor'])->toBe(3562500)
        ->and($s['totals']['after_advance_minor'])->toBe(1187500)
        ->and($terms)->toHaveCount(6)
        ->and(array_column($terms, 0))->toBe(['Payment', 'Materials', 'Delivery commencement', 'Validity', 'Delivery time', 'Transportation'])
        ->and($terms[0][1])->toBe('75% advance payment with work order.')
        ->and($terms[3][1])->toContain($this->quote->valid_until->format('d M Y'))
        ->and($terms[4][1])->toBe('15–20 days');
    $html = app(QuotePdf::class)->html($s);
    expect($html)->toContain('data-furniture-revision="5"', '35,625', '11,875', 'quotation-commercial-close', 'Imtiaz Ahmad', 'Nabeel Afzal')
        ->not->toContain('<section class="document-close">', 'Quotation validity is shown above.', 'Amount in words');
    expect(app(QuotePdf::class)->render($s))->toStartWith('%PDF-');
    expect(app(DocumentStyles::class)->quotationCopy(BusinessLine::Interior)['interior_revision'])->toBe(3)
        ->and(app(DocumentStyles::class)->copy(BusinessLine::Furniture)['furniture_revision'])->toBe(2)
        ->and(app(DocumentStyles::class)->copy(BusinessLine::Interior))->not->toHaveKey('quote_block_policy');
});

it('honours every block including items and totals for both new native brands', function (BusinessLine $line) {
    $quote = $line === BusinessLine::Furniture ? $this->quote : app(CreateQuote::class)->handle($this->contact, $line, $this->owner);
    $this->editor->updateLine($quote->items()->firstOrFail(), ['product_name' => 'Block product', 'description' => 'Uniquely identifiable BOQ item', 'qty' => '1', 'rate_minor' => 1234500]);
    $before = app(QuoteSnapshot::class)->build($quote->refresh());
    $hidden = array_map(static fn (array $block): array => ['key' => $block['key'], 'visible' => false], $before['document_design']['quote_blocks']);
    $this->editor->updateDocumentDesign($quote, ['quote_blocks' => $hidden]);
    $after = app(QuoteSnapshot::class)->build($quote->refresh());
    expect(DocumentStyles::visibleBlocks('quote', $after['document_design']))->toBe([])
        ->and($after['totals'])->toBe($before['totals']);
    $html = app(QuotePdf::class)->html($after);
    expect($html)->not->toContain('Uniquely identifiable BOQ item', '12,345', 'NET TOTAL', '<table class="financials">', '<table class="signatures">')
        ->and(app(QuotePdf::class)->render($after))->toStartWith('%PDF-');
    $this->editor->updateDocumentDesign($quote, ['quote_blocks' => $before['document_design']['quote_blocks']]);
    expect(app(QuotePdf::class)->html(app(QuoteSnapshot::class)->build($quote->refresh())))->toContain('Uniquely identifiable BOQ item', '12,345');
})->with([BusinessLine::Furniture, BusinessLine::Interior]);

it('offers actual click Show Hide with no duplicate new Furniture closing switches', function () {
    $page = Livewire::test(QuoteBuilder::class, ['quote' => $this->quote])->assertSee('PDF blocks')->assertDontSee('Always shown')->assertDontSee('Hide when optional');
    $index = collect($page->get('quoteBlocks'))->search(fn (array $b): bool => $b['key'] === 'terms');
    $before = $this->quote->refresh()->terms;
    $page->call('toggleQuoteBlock', $index)->assertDispatched('preview-refresh');
    $s = app(QuoteSnapshot::class)->build($this->quote->refresh());
    expect(app(QuotePdf::class)->html($s))->not->toContain('<h3>Terms and conditions</h3>')
        ->and($this->quote->terms)->toBe($before);
    $page->call('toggleQuoteBlock', $index)->call('toggleFurnitureOption', 'show_delivery');
    expect(app(QuotePdf::class)->html(app(QuoteSnapshot::class)->build($this->quote->refresh())))->toContain('Terms and conditions')->not->toContain('<dt class="term-label">Delivery time</dt>');
    expect($page->html())->not->toContain("toggleFurnitureOption('show_terms')", "toggleFurnitureOption('show_signatories')");
});

it('saves and reloads designer block switches for either brand while invoice locks remain', function (string $line) {
    $page = Livewire::test(DocumentDesigner::class)->set('line', $line);
    $index = collect($page->get('quoteBlocks'))->search(fn (array $b): bool => $b['key'] === 'boq');
    $page->call('toggleBlock', 'quote', $index)->call('save')->assertHasNoErrors();
    $copy = app(DocumentStyles::class)->quotationCopy(BusinessLine::from($line));
    expect(collect($copy['quote_blocks'])->firstWhere('key', 'boq')['visible'])->toBeFalse();
    expect(collect(DocumentStyles::blocks('invoice', [['key' => 'items', 'visible' => false]]))->firstWhere('key', 'items')['visible'])->toBeTrue();
    Livewire::test(DocumentDesigner::class)->set('line', $line)->assertSet('quoteBlocks.'.$index.'.visible', false);
})->with(['furniture', 'interior']);

it('preserves old percentage date and delivery when explicitly upgrading an old editable quote', function () {
    $meta = $this->quote->meta;
    $meta['document_design']['furniture_revision'] = 4;
    unset($meta['document_design']['quote_block_policy']);
    $meta['furniture']['show_terms'] = 'no';
    $this->quote->forceFill(['meta' => $meta, 'advance_bp' => 5000, 'valid_until' => today()->addDays(21)])->save();
    $this->editor->updateFurnitureText($this->quote, ['delivery_text' => 'Custom approved lead time']);
    $this->editor->updateDetails($this->quote, ['notes' => 'Retained client note']);
    $before = app(QuoteSnapshot::class)->build($this->quote->refresh());
    $this->editor->applyDocumentDesign($this->quote);
    $after = app(QuoteSnapshot::class)->build($this->quote->refresh());
    expect($after['furniture_revision'])->toBe(5)->and($after['totals'])->toBe($before['totals'])
        ->and($after['valid_until'])->toBe($before['valid_until'])->and($after['notes'])->toBe($before['notes'])
        ->and($after['furniture']['delivery_text'])->toBe('Custom approved lead time')
        ->and(DocumentStyles::visibleBlocks('quote', $after['document_design']))->not->toContain('terms');
});

it('preserves custom commercial text while payment and validity track saved fields', function () {
    $this->editor->updateDetails($this->quote, ['advance_bp' => 3750, 'valid_until' => today()->addDays(9), 'terms' => FurnitureDocument::TERMS."\nCustom finish warranty approved in writing.\nPayment: 50% upfront with work order."]);
    $s = app(QuoteSnapshot::class)->build($this->quote->refresh());
    $terms = FurnitureDocument::commercialTerms($s);
    expect($terms[0][1])->toBe('37.5% advance payment with work order.')
        ->and(array_column($terms, 1))->toContain('Custom finish warranty approved in writing.')
        ->and($terms[3][1])->toContain($this->quote->valid_until->format('d M Y'));
    expect(app(QuotePdf::class)->html($s))->not->toContain('50% upfront');
});

it('round trips brand blocks advance and delivery through a saved template', function (BusinessLine $line) {
    $quote = $line === BusinessLine::Furniture ? $this->quote : app(CreateQuote::class)->handle($this->contact, $line, $this->owner);
    $blocks = $quote->meta['document_design']['quote_blocks'];
    foreach ($blocks as &$block) {
        $block['visible'] = ! in_array($block['key'], ['boq', 'totals', 'terms'], true);
    }
    unset($block);
    $this->editor->updateDocumentDesign($quote, ['quote_blocks' => $blocks]);
    $this->editor->updateDetails($quote, ['advance_bp' => 5000]);
    $this->editor->updateFurnitureText($quote, ['delivery_text' => 'Client approved delivery']);
    $template = app(SaveQuoteAsTemplate::class)->handle($quote->refresh(), $line->value.' reusable');
    $copy = app(CreateQuote::class)->handle($this->contact, $line, $this->owner, template: $template);
    expect($template->business_line)->toBe($line)->and($copy->business_line)->toBe($line)
        ->and($copy->meta['document_design']['quote_blocks'])->toBe($blocks)
        ->and($copy->meta['furniture']['delivery_text'])->toBe('Client approved delivery');
    if ($line === BusinessLine::Furniture) {
        expect($copy->advance_bp)->toBe(5000);
    }
})->with([BusinessLine::Furniture, BusinessLine::Interior]);

it('refuses cross brand replacement and creation without changing either template', function () {
    $other = QuoteTemplate::query()->where('business_line', 'interior')->firstOrFail();
    $before = $other->getAttributes();
    expect(fn () => app(SaveQuoteAsTemplate::class)->handle($this->quote, 'Wrong brand', $other))->toThrow(ValidationException::class)
        ->and($other->refresh()->getAttributes())->toBe($before);
    expect(fn () => app(CreateQuote::class)->handle($this->contact, BusinessLine::Furniture, $this->owner, template: $other))->toThrow(InvalidArgumentException::class);
});

it('keeps signed client PDF public only after sending and serves exactly the frozen bytes', function () {
    $link = QuoteLinks::signed('quotes.portal.pdf', $this->quote);
    $this->get($link)->assertNotFound();
    app(SendQuote::class)->handle($this->quote, $this->owner, 'link', false);
    $version = $this->quote->versions()->firstOrFail();
    auth()->logout();
    $this->assertGuest();
    $this->get(QuoteLinks::signed('quotes.portal', $this->quote))->assertOk()->assertSee('Commercial revision QA')->assertSee('15–20 days')->assertSee('Transportation:')->assertSee('Advance with work order')->assertDontSee('INTERIOR STUDIO');
    $response = $this->get(QuoteLinks::signed('quotes.portal.pdf', $this->quote))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(hash('sha256', $response->streamedContent()))->toBe($version->pdf_sha256);
    $this->get('/q/'.$this->quote->public_id.'/pdf')->assertForbidden();
    $this->get($link.'&extra=tampered')->assertForbidden();
    $expired = URL::temporarySignedRoute('quotes.portal.pdf', now()->subMinute(), ['quote' => $this->quote->public_id], absolute: false);
    $this->get($expired)->assertForbidden();
    Storage::disk('local')->delete($version->pdf_path);
    $this->get(QuoteLinks::signed('quotes.portal.pdf', $this->quote))->assertNotFound();
});

it('uses the non plugin modal and distinct client page and PDF links', function () {
    $modal = view('components.admin.pdf-preview')->render();
    expect($modal)->toContain('documentPreview', 'PDF pages', 'Retry preview', 'Download PDF')->not->toContain('<iframe');
    app(SendQuote::class)->handle($this->quote, $this->owner, 'link', false);
    Livewire::test(QuoteBuilder::class, ['quote' => $this->quote->refresh()])->assertSee('Client page link')->assertSee('Client PDF link')->assertSee('Open shared PDF');
    expect(file_get_contents(resource_path('views/components/admin/quote-share-links.blade.php')))->toContain('new URL(value, window.location.href).href', 'Copy failed.');
});

it('retains hidden financial blocks and brand through template file export import', function (BusinessLine $line) {
    $quote = $line === BusinessLine::Furniture ? $this->quote : app(CreateQuote::class)->handle($this->contact, $line, $this->owner);
    $blocks = array_map(static fn (array $block): array => ['key' => $block['key'], 'visible' => false], $quote->meta['document_design']['quote_blocks']);
    $this->editor->updateDocumentDesign($quote, ['quote_blocks' => $blocks]);
    $template = app(SaveQuoteAsTemplate::class)->handle($quote->refresh(), 'Import block safety');
    $payload = ['format' => 'woodex-quote-template', 'version' => 1, 'name' => $template->name, 'business_line' => $line->value, 'kind' => $template->kind->value, 'milestone_plan' => $template->milestone_plan->value, 'terms' => $template->terms, 'blueprint' => $template->blueprint];
    $file = UploadedFile::fake()->createWithContent('blocks.json', json_encode($payload, JSON_THROW_ON_ERROR));
    Livewire::test(QuoteTemplates::class)->set('importFile', $file)->call('importTemplate')->assertHasNoErrors();
    $imported = QuoteTemplate::query()->latest('id')->firstOrFail();
    expect($imported->business_line)->toBe($line)->and($imported->blueprint['presentation']['quote_blocks'])->toBe($blocks);
    $copy = app(CreateQuote::class)->handle($this->contact, $line, $this->owner, template: $imported);
    expect(DocumentStyles::visibleBlocks('quote', $copy->meta['document_design']))->toBe([]);
})->with([BusinessLine::Furniture, BusinessLine::Interior]);

it('uses one percentage for the optional Furniture payment schedule and commercial terms', function () {
    $this->editor->updateDetails($this->quote, ['advance_bp' => 3750]);
    $blocks = $this->quote->refresh()->meta['document_design']['quote_blocks'];
    foreach ($blocks as &$block) {
        if ($block['key'] === 'payment') {
            $block['visible'] = true;
        }
    }
    unset($block);
    $this->editor->updateDocumentDesign($this->quote, ['quote_blocks' => $blocks]);
    $html = app(QuotePdf::class)->html(app(QuoteSnapshot::class)->build($this->quote->refresh()));
    expect($html)->toContain('Advance with work order', '37.5%', '62.5%', '17,812.50', '29,687.50', '37.5% advance payment with work order.', 'does not record money received');
});

it('does not revive obsolete closing flags after current block controls have taken ownership', function () {
    $this->editor->updateFurnitureText($this->quote, ['show_terms' => 'no']);
    $this->editor->applyDocumentDesign($this->quote);
    expect(DocumentStyles::visibleBlocks('quote', $this->quote->refresh()->meta['document_design']))->toContain('terms');
});

it('does not mistake explicitly shown Furniture master blocks for untouched legacy defaults', function () {
    $page = Livewire::test(DocumentDesigner::class)->set('line', 'furniture');
    foreach ($page->get('quoteBlocks') as $index => $block) {
        if (! $block['visible']) {
            $page->call('toggleBlock', 'quote', $index);
        }
    }
    $page->call('save')->assertHasNoErrors();
    expect(collect(DocumentStyles::visibleBlocks('quote', app(DocumentStyles::class)->quotationCopy(BusinessLine::Furniture)))->sort()->values()->all())->toBe(collect(array_keys(DocumentStyles::QUOTE_BLOCKS))->sort()->values()->all());
});
