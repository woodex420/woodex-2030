<?php

declare(strict_types=1);

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Sales\Actions\CreateFurnitureInvoice;
use App\Domain\Sales\Actions\CreateManualInvoice;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\SaveQuoteAsTemplate;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Enums\DiscountType;
use App\Domain\Sales\Services\FurnitureDocument;
use App\Domain\Sales\Services\InvoicePdf;
use App\Domain\Sales\Services\InvoiceSnapshot;
use App\Domain\Sales\Services\QuoteDraftPreview;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuotePdf;
use App\Domain\Sales\Services\QuoteSnapshot;
use App\Livewire\Admin\Sales\InvoiceCreate;
use App\Livewire\Admin\Sales\QuoteBuilder;
use App\Settings\SalesSettings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    Mail::fake();
    $this->owner = $this->userWithRole('owner');
    $this->actingAs($this->owner);
    $settings = app(SalesSettings::class);
    $settings->signatories = [['name' => 'QA Director', 'title' => 'Director'], ['name' => 'QA Manager', 'title' => 'Marketing Manager']];
    $settings->save();
    $this->contact = Contact::factory()->create();
    $this->quote = app(CreateQuote::class)->handle($this->contact, BusinessLine::Furniture, $this->owner);
    // Explicit historical fixture: keep regression coverage for the pre-flexible document.
    $meta = $this->quote->meta;
    unset($meta['document_design']['quote_block_policy']);
    $meta['document_design']['furniture_revision'] = 4;
    $this->quote->forceFill(['meta' => $meta, 'advance_bp' => 5000])->save();
    app(QuoteEditor::class)->updateLine($this->quote->items()->firstOrFail(), ['product_name' => 'Office chair', 'description' => 'Premium mesh with adjustable height.', 'rate_minor' => 4750000, 'qty' => '1', 'unit' => 'nos']);
    $this->quote->refresh();
});

it('prints the approved 47500 quotation with matching 50 percent terms and no obsolete labels', function () {
    $snapshot = app(QuoteSnapshot::class)->build($this->quote);
    expect($snapshot['furniture_revision'])->toBe(4)
        ->and($snapshot['totals']['advance_bp'])->toBe(5000)
        ->and($snapshot['totals']['advance_minor'])->toBe(2375000)
        ->and($snapshot['totals']['after_advance_minor'])->toBe(2375000);
    $html = app(QuotePdf::class)->html($snapshot);
    expect($html)->toContain('50% advance payment with work order.', '23,750', 'Terms and conditions', '>Product</th>', '>Description</th>', 'QA Director', 'QA Manager')
        ->not->toContain('Amount in words', 'Commercial notes', 'Specification', 'Shipping / transport', 'Rounding', '<td style="width:76%">Discount');
});

it('keeps exact paisa without rounding new furniture while leaving Interior rounding unchanged', function () {
    $settings = app(SalesSettings::class);
    $settings->round_totals_to_rupee = true;
    $settings->save();
    app(QuoteEditor::class)->updateLine($this->quote->items()->firstOrFail(), ['rate_minor' => 4750051]);
    expect($this->quote->refresh()->total_minor)->toBe(4750051)->and($this->quote->rounding_minor)->toBe(0);
    $interior = app(CreateQuote::class)->handle($this->contact, BusinessLine::Interior, $this->owner);
    app(QuoteEditor::class)->updateLine($interior->items()->firstOrFail(), ['rate_minor' => 4750051, 'qty' => '1']);
    expect($interior->refresh()->total_minor)->toBe(4750100)->and($interior->rounding_minor)->toBe(49);
});

it('updates payment wording from the same editable percentage and preserves other terms', function () {
    app(QuoteEditor::class)->updateDetails($this->quote, ['advance_bp' => 3750, 'terms' => "75% advance payment with work order.\nFinish subject to approval.\nPayment: 50% upfront with work order."]);
    $snapshot = app(QuoteSnapshot::class)->build($this->quote->refresh());
    expect($snapshot['totals']['advance_minor'])->toBe(1781250)
        ->and($snapshot['terms'])->toBe(['37.5% advance payment with work order.', 'Finish subject to approval.']);
});

it('hides optional empty rows but never hides applied discounts charges or tax', function () {
    app(QuoteEditor::class)->updateDetails($this->quote, ['discount_type' => DiscountType::Amount, 'discount_value' => 50000, 'other_charges_minor' => 100000, 'shipping_minor' => 200000, 'tax_bp' => 500]);
    $html = app(QuotePdf::class)->html(app(QuoteSnapshot::class)->build($this->quote->refresh()));
    expect($html)->toContain('>Discount</td>', '>Rent / Transport</td>', '>Tax</td>', '3,000')->not->toContain('Shipping / transport');
    expect($this->quote->total_minor)->toBe(5250000);
    app(QuoteEditor::class)->updateFurnitureText($this->quote, ['show_advance' => 'no', 'show_terms' => 'no', 'show_signatories' => 'no']);
    $html = app(QuotePdf::class)->html(app(QuoteSnapshot::class)->build($this->quote->refresh()));
    expect($html)->not->toContain('Terms and conditions</h3>', 'Advance payment (', 'QA Director');
});

it('adds removes and safely escapes individual centred signatories', function () {
    app(QuoteEditor::class)->updateFurnitureText($this->quote, ['signatories_text' => 'New Person | <script>alert(1)</script>']);
    $html = app(QuotePdf::class)->html(app(QuoteSnapshot::class)->build($this->quote->refresh()));
    expect($html)->toContain('New Person', '&lt;script&gt;')->not->toContain('QA Director', 'QA Manager', '<script>alert');
    app(QuoteEditor::class)->updateFurnitureText($this->quote, ['signatories_text' => '']);
    expect(app(QuotePdf::class)->html(app(QuoteSnapshot::class)->build($this->quote->refresh())))->not->toContain('<table class="signatures">');
    expect(FurnitureDocument::signatories("\nOne | A\n\nTwo | B"))->toHaveCount(2);
});

it('saves the real quote controls and projects optional content without a database write', function () {
    Livewire::test(QuoteBuilder::class, ['quote' => $this->quote])
        ->set('details.signatories_text', 'One Person | Director')->set('details.show_transport', 'no')
        ->assertHasNoErrors();
    expect($this->quote->refresh()->meta['furniture']['signatories_text'])->toBe('One Person | Director');
    $state = ['details' => ['show_signatories' => 'no']];
    $html = app(QuotePdf::class)->html(app(QuoteDraftPreview::class)->build($this->quote, $state));
    expect($html)->not->toContain('One Person')->and($this->quote->refresh()->meta['furniture']['show_signatories'])->toBe('yes');
});

it('retains the old renderer for unversioned historical snapshots and only upgrades editable drafts explicitly', function () {
    $meta = $this->quote->meta;
    unset($meta['document_design']['furniture_revision']);
    $this->quote->meta = $meta;
    $this->quote->save();
    app(QuoteEditor::class)->updateDetails($this->quote, ['advance_bp' => 7500]);
    $old = app(QuoteSnapshot::class)->build($this->quote->refresh());
    unset($old['furniture_revision']);
    $html = app(QuotePdf::class)->html($old);
    expect($html)->toContain('Item / Product', 'Commercial notes &amp; terms', '75%');
    app(QuoteEditor::class)->applyDocumentDesign($this->quote);
    expect($this->quote->refresh()->advance_bp)->toBe(7500)
        ->and($this->quote->meta['document_design']['furniture_revision'])->toBe(5)
        ->and(app(QuotePdf::class)->html($old))->toBe($html);
    app(SendQuote::class)->handle($this->quote, $this->owner, 'link', false);
    $frozen = $this->quote->versions()->firstOrFail()->snapshot;
    app(SalesSettings::class)->signatories = [];
    expect(app(QuotePdf::class)->html($frozen))->toContain('QA Director', '75% advance payment with work order.');
});

it('carries optional sections and custom signatories through reusable templates without resetting saved percentages', function () {
    app(QuoteEditor::class)->updateFurnitureText($this->quote, ['show_terms' => 'no', 'signatories_text' => 'Template Person | Director']);
    app(QuoteEditor::class)->updateDetails($this->quote, ['advance_bp' => 7500]);
    $template = app(SaveQuoteAsTemplate::class)->handle($this->quote->refresh(), 'Polished furniture');
    $copy = app(CreateQuote::class)->handle($this->contact, BusinessLine::Furniture, $this->owner, template: $template);
    expect($copy->advance_bp)->toBe(7500)->and($copy->meta['furniture']['show_terms'])->toBe('no')
        ->and($copy->meta['furniture']['signatories_text'])->toBe('Template Person | Director');
});

it('prints planned advance separately from actual invoice balance on both manual and converted invoices', function () {
    $manual = app(CreateManualInvoice::class)->handle($this->owner, ['contact_id' => $this->contact->id, 'business_line' => 'furniture', 'issue_date' => today()->toDateString(), 'due_date' => today()->toDateString(), 'items' => [['product_name' => 'Office chair', 'description' => 'Mesh chair', 'qty' => '1', 'rate_minor' => 4750000, 'unit' => 'nos']]]);
    expect($manual->advance_bp)->toBe(5000);
    app(SendQuote::class)->handle($this->quote, $this->owner, 'link', false);
    $converted = app(CreateFurnitureInvoice::class)->handle($this->quote->refresh(), $this->owner);
    foreach ([$manual, $converted] as $invoice) {
        $snapshot = app(InvoiceSnapshot::class)->build($invoice);
        expect($invoice->balance_minor)->toBe(4750000)->and($invoice->paid_minor)->toBe(0);
        expect(app(InvoicePdf::class)->html($snapshot))->toContain('50% advance payment with work order.', '23,750', 'Remaining after advance (planned)', 'Outstanding balance', 'QA Director');
        expect(app(InvoicePdf::class)->render($snapshot))->toStartWith('%PDF-');
    }
    Livewire::test(InvoiceCreate::class)->assertSet('advance', '50')->set('line', 'furniture')->assertSee('Centred signatories');
});

it('rejects invalid visibility options rather than accepting arbitrary values', function () {
    expect(fn () => FurnitureDocument::validatedText(['show_terms' => 'maybe']))->toThrow(ValidationException::class);
});
