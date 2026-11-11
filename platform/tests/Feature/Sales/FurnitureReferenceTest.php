<?php

declare(strict_types=1);

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Sales\Actions\CreateFurnitureInvoice;
use App\Domain\Sales\Actions\CreateManualInvoice;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Enums\DiscountType;
use App\Domain\Sales\Exceptions\QuoteLocked;
use App\Domain\Sales\Models\DocumentDesign;
use App\Domain\Sales\Services\DocumentStyles;
use App\Domain\Sales\Services\InvoicePdf;
use App\Domain\Sales\Services\InvoiceSnapshot;
use App\Domain\Sales\Services\QuoteDraftPreview;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuotePdf;
use App\Domain\Sales\Services\QuoteSnapshot;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    Notification::fake();
    $this->owner = $this->userWithRole('owner');
    $this->actingAs($this->owner);
    $this->client = Contact::factory()->create(['name' => 'Reference QA person', 'city' => 'Lahore']);
    $this->quote = app(CreateQuote::class)->handle($this->client, BusinessLine::Furniture, $this->owner);
    // Explicit historical fixture: keep regression coverage for the pre-flexible document.
    $meta = $this->quote->meta;
    unset($meta['document_design']['quote_block_policy']);
    $meta['document_design']['furniture_revision'] = 4;
    $this->quote->forceFill(['meta' => $meta, 'advance_bp' => 5000])->save();
    $this->editor = app(QuoteEditor::class);
    $this->editor->updateLine($this->quote->items()->firstOrFail(), ['product_name' => 'Office desk', 'description' => 'Solid oak veneer and cable management.', 'qty' => '2', 'rate_minor' => 8500000, 'unit' => 'nos']);
    $this->quote->refresh();
});

it('uses a quotation-only revision and local fonts without importing the sample editor or commercial defaults', function () {
    $s = app(QuoteSnapshot::class)->build($this->quote);
    expect($s['furniture_revision'])->toBe(4)->and($s['totals']['advance_bp'])->toBe(5000)
        ->and(app(DocumentStyles::class)->copy(BusinessLine::Furniture)['furniture_revision'])->toBe(2);
    $html = app(QuotePdf::class)->html($s);
    expect($html)->toContain('data-furniture-revision="4"', 'Prepared for', 'Project details', 'Location', 'class="financials"', 'font-family: \'Inter\'', 'data:font/ttf;base64,', '>Product</th>', '>Description</th>', '50% advance payment with work order.')
        ->not->toContain('cdn.tailwindcss.com', 'fonts.googleapis.com', 'contenteditable', 'calculateTotals', 'Item Code', '75%', '13%', '1-year warranty', 'Apex Office Solutions');
});

it('keeps native tax on transport and matching advance terms for the five-row reference amounts', function () {
    $section = $this->quote->sections()->firstOrFail();
    foreach ([['Chair', '2', 4250000], ['Table', '1', 14500000], ['Cabinet', '3', 5500000], ['Installation', '1', 3500000]] as [$name, $qty, $rate]) {
        $this->editor->addLine($section, ['product_name' => $name, 'description' => $name.' specifications', 'qty' => $qty, 'rate_minor' => $rate, 'unit' => 'nos']);
    }
    $this->editor->updateDetails($this->quote, ['discount_type' => DiscountType::Percent, 'discount_value' => 500, 'tax_bp' => 1300, 'other_charges_minor' => 1500000]);
    $s = app(QuoteSnapshot::class)->build($this->quote->refresh());
    expect($s['totals']['subtotal_minor'])->toBe(60000000)->and($s['totals']['tax_minor'])->toBe(7605000)
        ->and($s['totals']['total_minor'])->toBe(66105000)->and($s['totals']['advance_minor'])->toBe(33052500);
    $html = app(QuotePdf::class)->html($s);
    expect($html)->toContain('661,050', '330,525', '50% advance payment with work order.', 'Rent / Transport')->not->toContain('659,100');
    expect(app(QuotePdf::class)->render($s))->toStartWith('%PDF-');
});

it('keeps version two snapshots byte-identical in HTML when a draft explicitly adopts the new design', function () {
    $meta = $this->quote->meta;
    $meta['document_design']['furniture_revision'] = 2;
    $this->quote->meta = $meta;
    $this->quote->save();
    $this->editor->updateDetails($this->quote, ['advance_bp' => 3750]);
    $old = app(QuoteSnapshot::class)->build($this->quote->refresh());
    $html = app(QuotePdf::class)->html($old);
    expect($html)->toContain('data-furniture-revision="2"');
    $this->editor->applyDocumentDesign($this->quote);
    $fresh = app(QuoteSnapshot::class)->build($this->quote->refresh());
    expect($fresh['furniture_revision'])->toBe(5)->and($fresh['totals'])->toBe($old['totals'])
        ->and(app(QuotePdf::class)->html($old))->toBe($html)
        ->and(app(QuotePdf::class)->html($fresh))->toContain('37.5% advance payment with work order.');
});

it('does not move manual or converted invoices onto the new quotation renderer', function () {
    $manual = app(CreateManualInvoice::class)->handle($this->owner, ['contact_id' => $this->client->id, 'business_line' => 'furniture', 'issue_date' => today()->toDateString(), 'due_date' => today()->toDateString(), 'items' => [['product_name' => 'Desk', 'description' => 'Oak', 'qty' => '1', 'rate_minor' => 8500000, 'unit' => 'nos']]]);
    app(SendQuote::class)->handle($this->quote, $this->owner, 'link', false);
    $converted = app(CreateFurnitureInvoice::class)->handle($this->quote->refresh(), $this->owner);
    foreach ([$manual, $converted] as $invoice) {
        $html = app(InvoicePdf::class)->html(app(InvoiceSnapshot::class)->build($invoice));
        expect($html)->toContain('data-furniture-document="invoice"', 'data-furniture-revision="2"', 'Outstanding balance')->not->toContain('Prepared for', 'class="financials"');
    }
    expect(fn () => $this->editor->applyDocumentDesign($this->quote))->toThrow(QuoteLocked::class);
});

it('projects unsaved product and advance changes into the same new renderer without persisting them', function () {
    $id = $this->quote->items()->firstOrFail()->id;
    $s = app(QuoteDraftPreview::class)->build($this->quote, ['lines' => [$id => ['product_name' => 'Unsaved preview desk']], 'details' => ['advance' => '37.5']]);
    $html = app(QuotePdf::class)->html($s);
    expect($html)->toContain('data-furniture-revision="4"', 'Unsaved preview desk', '37.5% advance payment with work order.')
        ->and($this->quote->refresh()->advance_bp)->toBe(5000)->and($this->quote->items()->firstOrFail()->product_name)->toBe('Office desk');
});

it('keeps Interior on its existing renderer and does not upgrade old furniture on ordinary edits', function () {
    $interior = app(CreateQuote::class)->handle($this->client, BusinessLine::Interior, $this->owner);
    expect(app(QuotePdf::class)->html(app(QuoteSnapshot::class)->build($interior)))->not->toContain('data-furniture-revision="4"');
    $meta = $this->quote->meta;
    $meta['document_design']['furniture_revision'] = 2;
    $this->quote->meta = $meta;
    $this->quote->save();
    $this->editor->updateDetails($this->quote, ['title' => 'Still an old draft']);
    expect($this->quote->refresh()->meta['document_design']['furniture_revision'])->toBe(2);
});

it('preserves a sent version two snapshot and its stored PDF while the current design changes', function () {
    $meta = $this->quote->meta;
    $meta['document_design']['furniture_revision'] = 2;
    $this->quote->meta = $meta;
    $this->quote->save();
    app(SendQuote::class)->handle($this->quote, $this->owner, 'link', false);
    $version = $this->quote->versions()->firstOrFail();
    $snapshot = $version->snapshot;
    $bytes = Storage::disk('local')->get($version->pdf_path);
    $html = app(QuotePdf::class)->html($snapshot);
    DocumentDesign::where('business_line', 'furniture')->update(['brand_name' => 'Changed master brand']);
    expect($snapshot['furniture_revision'])->toBe(2)->and(app(QuotePdf::class)->html($snapshot))->toBe($html)
        ->and(Storage::disk('local')->get($version->pdf_path))->toBe($bytes)
        ->and(hash('sha256', $bytes))->toBe($version->pdf_sha256);
    expect(fn () => $this->editor->applyDocumentDesign($this->quote->refresh()))->toThrow(QuoteLocked::class);
});
