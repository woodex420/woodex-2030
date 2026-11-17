<?php

declare(strict_types=1);

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateManualInvoice;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuotePdf;
use App\Domain\Sales\Services\QuoteSnapshot;
use App\Livewire\Admin\Sales\InvoiceCreate;
use App\Livewire\Admin\Sales\InvoiceShow;
use Livewire\Livewire;

/*
| Step 46: the live preview is the document itself. v2.1 Pro's pane shows the same HTML that becomes
| the PDF; the old Slice-S drawer framed the PDF file, which Chrome blocked whenever the admin sat
| inside another origin's frame. These pins keep the pane honest for all three documents —
| interior quotation, furniture quotation, invoice — and keep the printed PDF free of screen CSS.
*/

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
    $this->actingAs($this->owner);
});

function livePreviewQuote($owner, BusinessLine $line = BusinessLine::Interior): Quote
{
    $lead = Lead::factory()->create(['owner_id' => $owner->id]);
    $quote = app(CreateQuote::class)->handle($lead->contact, $line, $owner, $lead);
    app(QuoteEditor::class)->updateLine($quote->items()->firstOrFail(), ['description' => 'Previewed joinery', 'rate_minor' => 100000]);

    return $quote->refresh();
}

it('keeps the printed document free of the screen-only preview sheet', function () {
    $snapshot = app(QuoteSnapshot::class)->build(livePreviewQuote($this->owner));

    $printed = view('pdf.quote', ['s' => $snapshot, 'draft' => false])->render();
    $pane = app(QuotePdf::class)->html($snapshot);

    expect($printed)->not->toContain('#e9e7e2')
        ->and($pane)->toContain('#e9e7e2')
        ->and($pane)->toContain('Prepared for')
        ->and($pane)->toContain('Previewed joinery')
        // The pane shows the clean document (as v2.1 does); the DRAFT watermark belongs to the PDF.
        ->and($pane)->not->toContain('class="watermark"');
});

it('previews a furniture quotation with its WF number and the dedicated green furniture sheet', function () {
    $snapshot = app(QuoteSnapshot::class)->build(livePreviewQuote($this->owner, BusinessLine::Furniture));
    $pane = app(QuotePdf::class)->html($snapshot);

    expect($snapshot['label'])->toStartWith('WF-')
        ->and($pane)->toContain('data-furniture-document="quotation"')
        ->and($pane)->toContain('#347647')
        ->and($pane)->toContain('Previewed joinery');
});

it('previews a new invoice from the form as typed, before anything is saved', function () {
    $contact = Contact::factory()->create(['name' => 'Preview Client']);

    Livewire::test(InvoiceCreate::class)
        ->set('contactId', $contact->id)
        ->set('items.0.description', 'Wardrobe hinge replacement')
        ->set('items.0.qty', '2')
        ->set('items.0.rate', '15000')
        ->set('showPreview', true)
        ->assertSee('Invoice live preview')
        ->assertSee('Wardrobe hinge replacement')
        ->assertSee('DRAFT — not issued')
        ->set('items.0.description', 'Wardrobe hinge and lock replacement')
        ->assertDispatched('preview-refresh');
});

it('previews a draft invoice on its page', function () {
    $contact = Contact::factory()->create();
    $invoice = app(CreateManualInvoice::class)->handle($this->owner, [
        'contact_id' => $contact->id,
        'business_line' => 'interior',
        'issue_date' => today()->toDateString(),
        'due_date' => today()->addDays(14)->toDateString(),
        'bank_account_id' => null,
        'discount_minor' => 0,
        'tax_bp' => 0,
        'notes' => '',
        'terms' => '',
        'items' => [['description' => 'Site measurement visit', 'unit' => 'job', 'qty' => '1', 'rate_minor' => 500000]],
        'issue' => false,
    ]);

    Livewire::test(InvoiceShow::class, ['invoice' => $invoice])
        ->assertDontSee('Invoice live preview')
        ->set('showPreview', true)
        ->assertSee('Invoice live preview')
        ->assertSee('DRAFT — not issued');
});

it('lets the admin frame its own PDFs while HTML pages keep SAMEORIGIN', function () {
    config()->set('woodex.security.frame_options', 'SAMEORIGIN');

    $pdf = $this->get(route('admin.quotes.pdf', livePreviewQuote($this->owner)));
    $pdf->assertOk()->assertHeaderMissing('X-Frame-Options');
    expect($pdf->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'self'");

    $this->get(route('admin.integrations'))->assertOk()->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});
