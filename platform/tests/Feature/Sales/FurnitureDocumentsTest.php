<?php

declare(strict_types=1);

use App\Domain\Accounts\Models\JournalEntry;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Sales\Actions\AcceptQuote;
use App\Domain\Sales\Actions\CreateFurnitureInvoice;
use App\Domain\Sales\Actions\CreateManualInvoice;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\DuplicateQuote;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Actions\RaiseAdvanceInvoice;
use App\Domain\Sales\Actions\RaiseMilestoneInvoice;
use App\Domain\Sales\Actions\SaveDocumentDesign;
use App\Domain\Sales\Actions\SaveQuoteAsTemplate;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Actions\VerifyPayment;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Exceptions\QuoteActionDenied;
use App\Domain\Sales\Models\DocumentDesign;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Services\InvoicePdf;
use App\Domain\Sales\Services\InvoiceSnapshot;
use App\Domain\Sales\Services\QuoteDraftPreview;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuotePdf;
use App\Domain\Sales\Services\QuoteSnapshot;
use App\Livewire\Admin\Sales\InvoiceCreate;
use App\Livewire\Admin\Sales\QuoteCreate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    Mail::fake();
    $this->owner = $this->userWithRole('owner');
    $this->actingAs($this->owner);
    $this->contact = Contact::factory()->create();
    $this->quote = app(CreateQuote::class)->handle($this->contact, BusinessLine::Furniture, $this->owner, title: 'Office furniture');
    app(QuoteEditor::class)->updateLine($this->quote->items()->firstOrFail(), [
        'product_name' => 'Executive workstation', 'description' => 'Al-Noor tactile top; powder-coated MS frame; imported hardware.',
        'unit' => 'nos', 'qty' => '1', 'rate_minor' => 49000000,
    ]);
    app(QuoteEditor::class)->updateDetails($this->quote, ['advance_bp' => 7500]);
    $this->quote->refresh();
});

function sendFurnitureDocument($context): void
{
    app(SendQuote::class)->handle($context->quote, $context->owner, 'link', false);
    $context->quote->refresh();
}

it('offers both brands and filters new quotation templates without changing a chosen brand', function () {
    Livewire::test(QuoteCreate::class)->assertSee('Woodex Interior')->assertSee('Woodex Furniture')
        ->call('chooseBrand', 'furniture')->assertSet('line', 'furniture')
        ->assertSee('Furniture')->call('selectContact', $this->contact->id)->assertSet('line', 'furniture');
});

it('renders the dedicated sheet with an explicitly selected 75 percent advance', function () {
    $s = app(QuoteSnapshot::class)->build($this->quote);
    expect($s['quote_layout'])->toBe('furniture')
        ->and($this->quote->meta['furniture_billing'])->toBe('full')
        ->and($this->quote->terms)->not->toContain('50%')
        ->and($s['totals']['advance_minor'])->toBe(36750000)
        ->and($s['totals']['after_advance_minor'])->toBe(12250000);
    $html = app(QuotePdf::class)->html($s);
    expect($html)->toContain('>Product</th>')->toContain('>Description</th>')
        ->toContain('Executive workstation')->toContain('Al-Noor tactile top')
        ->toContain('367,500')->toContain('122,500')->toContain('data-furniture-document="quotation"')
        ->and(app(QuotePdf::class)->render($s, draft: true))->toStartWith('%PDF-');
});

it('taxes rent and shipping after discounts and calculates the advance from the grand total', function () {
    app(QuoteEditor::class)->updateDetails($this->quote, ['other_charges_minor' => 100000, 'shipping_minor' => 200000, 'tax_bp' => 500]);
    $s = app(QuoteSnapshot::class)->build($this->quote->refresh());
    expect($s['totals']['tax_minor'])->toBe(2465000)
        ->and($s['totals']['total_minor'])->toBe(51765000)
        ->and($s['totals']['advance_minor'])->toBe(38823750)
        ->and($s['totals']['after_advance_minor'])->toBe(12941250);
});

it('rejects invalid new commercial values at the domain boundary', function (array $values) {
    expect(fn () => app(QuoteEditor::class)->updateDetails($this->quote, $values))->toThrow(ValidationException::class);
})->with([[['shipping_minor' => -1]], [['advance_bp' => 10001]], [['advance_bp' => -1]]]);

it('projects unsaved furniture product, shipping and advance without any database writes', function () {
    $line = $this->quote->items()->firstOrFail();
    $s = app(QuoteDraftPreview::class)->build($this->quote, [
        'lines' => [$line->id => ['product_name' => 'Mesh chair', 'description' => 'Imported black mesh', 'qty' => '2', 'rate' => '5000']],
        'details' => ['shipping' => '1000', 'advance' => '50', 'delivery_text' => '8–10 working days'],
    ]);
    expect($s['sections'][0]['items'][0]['product_name'])->toBe('Mesh chair')
        ->and($s['totals']['shipping_minor'])->toBe(100000)
        ->and($s['totals']['advance_minor'])->toBe(550000)
        ->and($line->fresh()->product_name)->toBe('Executive workstation')
        ->and($this->quote->fresh()->shipping_minor)->toBe(0);
});

it('roundtrips product specifications and advance through reusable templates and duplicates', function () {
    app(QuoteEditor::class)->updateFurnitureText($this->quote, ['delivery_text' => '8–10 working days']);
    $template = app(SaveQuoteAsTemplate::class)->handle($this->quote->refresh(), 'Reusable furniture proof');
    $new = app(CreateQuote::class)->handle($this->contact, BusinessLine::Furniture, $this->owner, template: $template);
    expect($new->items()->firstOrFail()->product_name)->toBe('Executive workstation')
        ->and($new->items()->firstOrFail()->description)->toContain('Al-Noor')
        ->and($new->meta['furniture']['delivery_text'])->toBe('8–10 working days')
        ->and($new->advance_bp)->toBe(7500);
    $copy = app(DuplicateQuote::class)->handle($this->quote, $this->owner);
    expect($copy->items()->firstOrFail()->product_name)->toBe('Executive workstation');
});

it('does not mistake a manually printed heading for an accounting invoice', function () {
    app(QuoteEditor::class)->updateFurnitureText($this->quote, ['document_title' => 'Invoice']);
    $html = app(QuotePdf::class)->html(app(QuoteSnapshot::class)->build($this->quote->refresh()));
    expect($html)->toContain('Quotation document')->toContain('<div class="document-title">Invoice</div>')
        ->and(Invoice::count())->toBe(0);
});

it('creates one full draft from frozen sent content and does not assume the advance is paid', function () {
    app(QuoteEditor::class)->updateDetails($this->quote, ['scope_html' => '<p>Approved furniture scope</p>']);
    sendFurnitureDocument($this);
    $this->contact->update(['name' => 'Changed after send']);
    $invoice = app(CreateFurnitureInvoice::class)->handle($this->quote, $this->owner);
    expect($invoice->number)->toStartWith('WFI-')->and($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->source_quote_id)->toBe($this->quote->id)
        ->and($invoice->total_minor)->toBe(49000000)->and($invoice->paid_minor)->toBe(0)
        ->and($invoice->balance_minor)->toBe(49000000)
        ->and($invoice->items()->firstOrFail()->product_name)->toBe('Executive workstation')
        ->and($invoice->commercial['client']['name'])->not->toBe('Changed after send')
        ->and(app(CreateFurnitureInvoice::class)->handle($this->quote, $this->owner)->id)->toBe($invoice->id)
        ->and(Invoice::count())->toBe(1)
        ->and(JournalEntry::where('source_type', 'invoice')->count())->toBe(0);
    $snapshot = app(InvoiceSnapshot::class)->build($invoice);
    expect(app(InvoicePdf::class)->html($snapshot))->toContain('Outstanding balance')->toContain('490,000')->toContain('367,500')->toContain('Approved furniture scope')
        ->and(app(InvoicePdf::class)->render($snapshot))->toStartWith('%PDF-');
});

it('preserves charges, tax and optional exclusions when converting the sent quotation', function () {
    app(QuoteEditor::class)->updateDetails($this->quote, ['shipping_minor' => 200000, 'other_charges_minor' => 100000, 'tax_bp' => 500]);
    app(QuoteEditor::class)->addLine($this->quote->sections()->firstOrFail(), ['product_name' => 'Optional chair', 'description' => 'Not included', 'is_optional' => true, 'qty' => '1', 'rate_minor' => 500000]);
    sendFurnitureDocument($this);
    $invoice = app(CreateFurnitureInvoice::class)->handle($this->quote, $this->owner);
    expect($invoice->total_minor)->toBe(51765000)->and($invoice->tax_minor)->toBe(2465000)
        ->and($invoice->rent_minor)->toBe(100000)->and($invoice->shipping_minor)->toBe(200000)
        ->and($invoice->items()->count())->toBe(1);
});

it('requires a sent furniture quotation and a permitted active accounts actor', function () {
    expect(fn () => app(CreateFurnitureInvoice::class)->handle($this->quote, $this->owner))->toThrow(QuoteActionDenied::class);
    sendFurnitureDocument($this);
    expect(fn () => app(CreateFurnitureInvoice::class)->handle($this->quote, $this->userWithRole('sales')))->toThrow(AuthorizationException::class);
});

it('does not create milestone invoices on acceptance or in either milestone billing path', function () {
    sendFurnitureDocument($this);
    app(AcceptQuote::class)->handle($this->quote, 'Review client', null, null, null);
    $this->quote->refresh();
    expect(Invoice::count())->toBe(0)->and(app(RaiseAdvanceInvoice::class)->handle($this->quote))->toBeNull();
    $milestone = $this->quote->project->milestones()->firstOrFail();
    expect(fn () => app(RaiseMilestoneInvoice::class)->handle($this->quote, $milestone, $this->owner))->toThrow(QuoteActionDenied::class);
    expect(fn () => app(App\Domain\Projects\Actions\RaiseMilestoneInvoice::class)->handle($milestone, $this->owner))->toThrow(ValidationException::class);
    expect(Artisan::call('woodex:invoice-milestones'))->toBe(0);
    expect(Invoice::count())->toBe(0);
});

it('never bypasses an archived full invoice', function () {
    sendFurnitureDocument($this);
    app(CreateFurnitureInvoice::class)->handle($this->quote, $this->owner)->delete();
    expect(fn () => app(CreateFurnitureInvoice::class)->handle($this->quote, $this->owner))->toThrow(QuoteActionDenied::class)
        ->and(Invoice::withTrashed()->count())->toBe(1);
});

it('refuses conversion when an older invoice exists even if archived', function () {
    sendFurnitureDocument($this);
    $invoice = app(CreateManualInvoice::class)->handle($this->owner, [
        'contact_id' => $this->contact->id, 'business_line' => 'furniture', 'issue_date' => today()->toDateString(), 'due_date' => today()->toDateString(),
        'items' => [['description' => 'Previously billed', 'qty' => '1', 'unit' => 'nos', 'rate_minor' => 100000]],
    ]);
    $invoice->update(['quote_id' => $this->quote->id]);
    $invoice->delete();
    expect(fn () => app(CreateFurnitureInvoice::class)->handle($this->quote, $this->owner))->toThrow(QuoteActionDenied::class);
});

it('issues and books the full sale, then reduces actual balance only after verification and issues a receipt', function () {
    sendFurnitureDocument($this);
    $invoice = app(CreateFurnitureInvoice::class)->handle($this->quote, $this->owner);
    app(IssueInvoice::class)->handle($invoice, $this->owner);
    $invoice->refresh();
    expect($invoice->status)->toBe(InvoiceStatus::Issued)->and($invoice->snapshot['furniture_renderer'])->toBe('furniture-v1')
        ->and(Storage::disk('local')->get($invoice->pdf_path))->toStartWith('%PDF-')
        ->and(JournalEntry::where('source_type', 'invoice')->where('source_id', $invoice->id)->count())->toBe(1);
    $payment = Payment::create(['invoice_id' => $invoice->id, 'contact_id' => $this->contact->id,
        'amount_minor' => 36750000, 'received_on' => today(), 'method' => 'cash', 'status' => 'pending_verification']);
    expect($invoice->fresh()->balance_minor)->toBe(49000000);
    $receipt = app(VerifyPayment::class)->handle($payment, $this->owner);
    expect($invoice->fresh()->balance_minor)->toBe(12250000)->and($invoice->fresh()->paid_minor)->toBe(36750000)
        ->and($receipt->receipt_number)->not->toBeNull()
        ->and(JournalEntry::where('source_type', 'payment')->where('source_id', $payment->id)->count())->toBe(1);
});

it('uses the same furniture renderer and charge arithmetic in the editable new invoice form', function () {
    Livewire::test(InvoiceCreate::class)->set('line', 'furniture')->set('contactId', $this->contact->id)
        ->set('items.0.product_name', 'Task chair')->set('items.0.description', 'Imported black mesh')
        ->set('items.0.rate', '490000')->set('rent', '1000')->set('shipping', '2000')->set('taxPercent', '5')
        ->assertSee('Invoice live preview')->assertSee('Imported black mesh')->assertSee('517,650')
        ->call('saveDraft')->assertHasNoErrors();
    $invoice = Invoice::sole();
    expect($invoice->number)->toStartWith('WFI-')->and($invoice->total_minor)->toBe(51765000)
        ->and($invoice->items()->firstOrFail()->product_name)->toBe('Task chair');
});

it('copies safe customizable furniture identity and never mutates sent snapshot bytes', function () {
    sendFurnitureDocument($this);
    $frozen = $this->quote->versions()->firstOrFail()->snapshot;
    $design = DocumentDesign::where('business_line', 'furniture')->firstOrFail();
    app(SaveDocumentDesign::class)->handle($this->owner, 'furniture', [
        'brand_name' => 'Furniture proof', 'accent_color' => '#225544', 'header_text' => 'Custom header', 'footer_text' => 'Custom footer',
        'furniture_style' => ['secondary_color' => '#dddddd', 'header_align' => 'left', 'footer_align' => 'right', 'quotation_title' => 'Supply quotation', 'invoice_title' => 'Tax invoice', 'use_default_logo' => 'no'],
    ], $design->revision);
    $new = app(CreateQuote::class)->handle($this->contact, BusinessLine::Furniture, $this->owner);
    $html = app(QuotePdf::class)->html(app(QuoteSnapshot::class)->build($new));
    expect($html)->toContain('#225544')->toContain('#dddddd')->toContain('Supply quotation')->toContain('Custom footer')
        ->and($this->quote->versions()->firstOrFail()->snapshot)->toBe($frozen);
});

it('roundtrips the additive schema without resetting allocated invoice numbers', function () {
    $migration = require database_path('migrations/2026_10_27_200000_add_furniture_documents.php');
    DB::table('number_sequences')->where('key', 'furniture.invoice')->update(['next_number' => 17]);
    $migration->down();
    expect(Schema::hasColumn('invoices', 'source_quote_id'))->toBeFalse();
    $migration->up();
    expect(Schema::hasColumn('invoices', 'source_quote_id'))->toBeTrue()
        ->and(DB::table('number_sequences')->where('key', 'furniture.invoice')->value('next_number'))->toBe(17);
});

it('rejects unsafe furniture style values and overlong product names', function () {
    $design = DocumentDesign::where('business_line', 'furniture')->firstOrFail();
    expect(fn () => app(SaveDocumentDesign::class)->handle($this->owner, 'furniture', [
        'brand_name' => 'Woodex Furniture', 'accent_color' => '#225544', 'header_text' => '', 'footer_text' => '',
        'furniture_style' => ['secondary_color' => '#fff;display:none'],
    ], $design->revision))->toThrow(ValidationException::class);
    expect(fn () => app(QuoteEditor::class)->updateLine($this->quote->items()->firstOrFail(), ['product_name' => str_repeat('A', 161)]))->toThrow(ValidationException::class);
});
