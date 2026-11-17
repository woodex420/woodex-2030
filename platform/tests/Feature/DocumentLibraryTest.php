<?php

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\AcceptQuote;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\SaveDescriptionTemplate;
use App\Domain\Sales\Actions\SaveDocumentDesign;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Exceptions\QuoteLocked;
use App\Domain\Sales\Models\DescriptionTemplate;
use App\Domain\Sales\Models\DocumentDesign;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Services\QuoteApprovalRules;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuotePdf;
use App\Domain\Sales\Services\QuoteSnapshot;
use App\Livewire\Admin\Sales\DescriptionLibrary;
use App\Livewire\Admin\Sales\DocumentDesigner;
use App\Livewire\Admin\Sales\QuoteBuilder;
use App\Livewire\Admin\Sales\RateBook;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

it('renders searchable sectioned libraries for both brands and restricts editing', function () {
    $this->get(route('admin.sales.documents', ['tab' => 'descriptions']))->assertOk()->assertSee('Kitchen joinery')->assertSee('Executive table specification');
    Livewire::test(DescriptionLibrary::class)->set('line', 'furniture')->assertSee('Executive table specification')->assertDontSee('Kitchen joinery');
    $sales = $this->userWithRole('sales');
    Livewire::actingAs($sales)->test(DescriptionLibrary::class)->call('openCreate')->assertForbidden();
    $this->actingAs($this->userWithRole('editor'))->get(route('admin.sales.documents', ['tab' => 'descriptions']))->assertForbidden();
});

it('saves and archives descriptions without updating copied quotes', function () {
    $template = DescriptionTemplate::query()->where('business_line', 'interior')->firstOrFail();
    $line = app(QuoteEditor::class)->addDescriptionLine($this->quote->sections()->firstOrFail(), $template->id);
    Livewire::test(DescriptionLibrary::class)->call('openEdit', $template->id)->set('form.body', 'Revised master specification')->set('form.is_active', false)->call('save')->assertHasNoErrors();
    expect($template->refresh()->is_active)->toBeFalse()->and($line->refresh()->description)->not->toBe('Revised master specification');
});

it('rejects oversized descriptions rather than truncating them', function () {
    Livewire::test(DescriptionLibrary::class)->call('openCreate')->set('form.section', 'Joinery')->set('form.title', 'Large description')->set('form.body', str_repeat('x', 501))->call('save')->assertHasErrors('form.body');
});

it('validates description writes in the domain action too', function () {
    app(SaveDescriptionTemplate::class)->handle($this->owner, ['business_line' => 'furniture', 'section' => 'Desks', 'title' => '  ', 'body' => 'Text', 'is_active' => true]);
})->throws(ValidationException::class);

it('copies a description as a new unpriced line and preserves existing prices', function () {
    $old = $this->quote->items()->firstOrFail();
    $template = DescriptionTemplate::query()->where('business_line', 'interior')->firstOrFail();
    Livewire::test(QuoteBuilder::class, ['quote' => $this->quote])->call('openDescriptions', $old->quote_section_id)->call('addDescription', $template->id)->assertHasNoErrors();
    $new = $this->quote->items()->where('description', $template->body)->firstOrFail();
    expect($new->description)->toBe($template->body)->and($new->rate_minor)->toBe(0)->and($old->refresh()->rate_minor)->toBe(100000);
});

it('refuses wrong-brand or archived description copies', function (string $case) {
    $template = DescriptionTemplate::query()->where('business_line', $case === 'brand' ? 'furniture' : 'interior')->firstOrFail();
    if ($case === 'inactive') {
        $template->update(['is_active' => false]);
    }
    app(QuoteEditor::class)->addDescriptionLine($this->quote->sections()->firstOrFail(), $template->id);
})->with(['brand', 'inactive'])->throws(ModelNotFoundException::class);

it('refuses description and design changes on locked quotations', function (string $status) {
    $this->quote->update(['status' => $status]);
    $before = $this->quote->meta;
    Livewire::test(QuoteBuilder::class, ['quote' => $this->quote])->call('applyCurrentDesign');
    expect($this->quote->refresh()->meta)->toBe($before);
    expect(fn () => app(QuoteEditor::class)->applyDocumentDesign($this->quote))->toThrow(QuoteLocked::class);
})->with(['sent', 'accepted', 'pending_approval', 'approved']);

it('starts a catalog form from a description without importing a selling rate', function () {
    $template = DescriptionTemplate::query()->where('business_line', 'furniture')->firstOrFail();
    Livewire::withQueryParams(['description' => $template->id])->test(RateBook::class)->assertSet('showForm', true)->assertSet('formDescription', $template->body)->assertSet('formLine', 'furniture')->assertSet('formRate', '');
});

it('protects document design configuration and preserves copied draft branding', function () {
    $old = app(QuoteSnapshot::class)->build($this->quote)['document_design'];
    app(SaveDocumentDesign::class)->handle($this->owner, 'interior', $this->designValues, 1);
    expect(app(QuoteSnapshot::class)->build($this->quote)['document_design'])->toBe($old);
    app(QuoteEditor::class)->applyDocumentDesign($this->quote);
    expect(app(QuoteSnapshot::class)->build($this->quote->refresh())['document_design']['revision'])->toBe(2);
    $this->actingAs($this->userWithRole('sales'))->get(route('admin.sales.documents', ['tab' => 'design']))->assertForbidden();
});

it('updates the design via the Livewire form', function () {
    Livewire::test(DocumentDesigner::class)->set('form.footer_text', 'Reviewed footer')->call('save')->assertHasNoErrors()->assertSet('revision', 2);
    expect(DocumentDesign::query()->where('business_line', 'interior')->value('footer_text'))->toBe('Reviewed footer');
});

it('rejects stale document design writes', function () {
    app(SaveDocumentDesign::class)->handle($this->owner, 'interior', $this->designValues, 1);
    app(SaveDocumentDesign::class)->handle($this->owner, 'interior', $this->designValues, 1);
})->throws(HttpException::class);

it('rejects unsafe design colors', function (string $value) {
    Livewire::test(DocumentDesigner::class)->set('form.accent_color', $value)->call('save')->assertHasErrors('form.accent_color');
})->with(['red', '#fff', '#fff;display:none', '<script>']);

it('rejects HTML and SVG instead of accepting them as logos', function (string $name, string $body) {
    $file = UploadedFile::fake()->createWithContent($name, $body);
    app(SaveDocumentDesign::class)->handle($this->owner, 'interior', $this->designValues, 1, $file);
})->with([['logo.html', '<script>alert(1)</script>'], ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>']])->throws(ValidationException::class);

it('binds explicitly applied document design to quotation approval', function () {
    $before = app(QuoteApprovalRules::class)->assess($this->quote, $this->owner)->fingerprint;
    app(SaveDocumentDesign::class)->handle($this->owner, 'interior', $this->designValues, 1);
    expect(app(QuoteApprovalRules::class)->assess($this->quote->refresh(), $this->owner)->fingerprint)->toBe($before);
    app(QuoteEditor::class)->applyDocumentDesign($this->quote);
    expect(app(QuoteApprovalRules::class)->assess($this->quote->refresh(), $this->owner)->fingerprint)->not->toBe($before);
});

it('keeps furniture and project design families distinct', function (BusinessLine $line, string $brand) {
    $quote = app(CreateQuote::class)->handle($this->lead->contact, $line, $this->owner);
    expect(app(QuoteSnapshot::class)->build($quote)['document_design']['brand_name'])->toBe($brand);
})->with([[BusinessLine::Furniture, 'Woodex Furniture'], [BusinessLine::Project, 'Woodex Interior']]);

it('serves the exact frozen quote PDF by default after sending', function () {
    $version = app(SendQuote::class)->handle($this->quote, $this->owner);
    $bytes = Storage::disk('local')->get($version->pdf_path);
    app(SaveDocumentDesign::class)->handle($this->owner, 'interior', $this->designValues, 1);
    $this->get(route('admin.quotes.pdf', $this->quote))->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertStreamedContent($bytes);
    expect($version->refresh()->pdf_sha256)->toBe(hash('sha256', $bytes));
});

it('escapes design text in print views and renders real draft PDFs', function () {
    $values = $this->designValues;
    $values['footer_text'] = '<script>unsafe()</script>';
    app(SaveDocumentDesign::class)->handle($this->owner, 'interior', $values, 1);
    app(QuoteEditor::class)->applyDocumentDesign($this->quote);
    $snapshot = app(QuoteSnapshot::class)->build($this->quote->refresh());
    $html = view('pdf.quote', ['s' => $snapshot, 'draft' => true])->render();
    expect($html)->toContain('&lt;script&gt;unsafe()&lt;/script&gt;')->not->toContain('<script>unsafe()');
    expect(app(QuotePdf::class)->render($snapshot, draft: true))->toStartWith('%PDF-');
});

it('creates and freezes the existing advance invoice flow with matching design', function () {
    $version = app(SendQuote::class)->handle($this->quote, $this->owner);
    app(AcceptQuote::class)->handle($this->quote->refresh(), 'Preview Client', null, '127.0.0.1', 'test');
    $invoice = Invoice::query()->where('quote_id', $this->quote->id)->firstOrFail();
    expect($invoice->status)->toBe(InvoiceStatus::Issued)->and($invoice->snapshot['document_design'])->toBe($version->snapshot['document_design']);
    $this->get(route('admin.invoices.pdf', $invoice))->assertOk()->assertStreamedContent(Storage::disk('local')->get($invoice->pdf_path));
    Storage::disk('local')->delete($invoice->pdf_path);
    $this->get(route('admin.invoices.pdf', $invoice))->assertStatus(409);
});

it('uses in-window preview events rather than new-tab PDF links', function () {
    $this->get(route('admin.quotes.show', $this->quote))->assertOk()->assertSee('document-preview', false)->assertSee('Close document preview')->assertDontSee('target="_blank" rel="noopener">Preview PDF', false);
});

it('embeds a valid local logo into immutable quote design copies', function () {
    $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAACAAAAAQCAIAAAD4YuoOAAAAHUlEQVR4nGMQMgmjKWIYtWDUglELRi0YtWAoWAAAdW84EGJ6uxkAAAAASUVORK5CYII=');
    $logo = UploadedFile::fake()->createWithContent('brand.png', $bytes);
    $design = app(SaveDocumentDesign::class)->handle($this->owner, 'interior', $this->designValues, 1, $logo);
    app(QuoteEditor::class)->applyDocumentDesign($this->quote);
    $snapshot = app(QuoteSnapshot::class)->build($this->quote->refresh());
    expect($snapshot['document_design']['logo_sha256'])->toBe(hash('sha256', $bytes));
    expect(app(QuotePdf::class)->render($snapshot, draft: true))->toStartWith('%PDF-');
    app(SaveDocumentDesign::class)->handle($this->owner, 'interior', $this->designValues, 2, removeLogo: true);
    expect(app(QuoteSnapshot::class)->build($this->quote->refresh())['document_design']['logo_data_uri'])->toBe($design->logo_data_uri);
});

it('rejects a stale inactive actor for library writes', function () {
    $this->owner->update(['is_active' => false]);
    app(SaveDocumentDesign::class)->handle($this->owner, 'interior', $this->designValues, 1);
})->throws(HttpException::class);
