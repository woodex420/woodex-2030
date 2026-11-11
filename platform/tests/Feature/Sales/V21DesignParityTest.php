<?php

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateManualInvoice;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Services\InvoiceSnapshot;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuoteSnapshot;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    $this->owner = $this->userWithRole('owner');
    $this->actingAs($this->owner);
    $this->lead = Lead::factory()->create(['owner_id' => $this->owner->id]);
    $this->quote = app(CreateQuote::class)->handle($this->lead->contact, BusinessLine::Interior, $this->owner, $this->lead);
    // These tests pin the original v2.1 contract; current Interior styling has its own reference suite.
    $meta = $this->quote->meta;
    unset($meta['document_design']['interior_revision']);
    $this->quote->meta = $meta;
    $this->quote->save();
});

it('prints the v2.1 boxes, palette, unit labels and intro position on quotations', function () {
    $company = Company::query()->create(['name' => 'Acme Builders (Pvt) Ltd']);
    $this->quote->contact->update(['company_id' => $company->id]);
    $this->quote->forceFill(['terms' => "50% advance payment with work order.\nRates are valid for 15 days."])->save();

    $editor = app(QuoteEditor::class);
    $editor->updateDetails($this->quote, ['type' => 'Renovation', 'site' => 'DHA Phase 6, Lahore', 'intro_line' => 'Thank you for the opportunity.']);
    $section = $this->quote->sections()->firstOrFail();
    $editor->updateLine($section->items()->firstOrFail(), ['description' => 'Brick work', 'unit' => 'sft', 'qty' => '724.875', 'rate_minor' => 100050]);
    $lump = $editor->addSection($this->quote, 'Demolition');
    $editor->updateLine($editor->addLine($lump), ['description' => 'Removal work', 'unit' => 'lumpsum', 'qty' => 1, 'rate_minor' => 5000000]);
    $tile = $editor->addSection($this->quote, 'Flooring');
    $editor->updateLine($editor->addLine($tile), ['description' => 'Floor tile', 'unit' => 'sqmt', 'qty' => 10, 'rate_minor' => 320000]);

    $html = view('pdf.quote', ['s' => app(QuoteSnapshot::class)->build($this->quote->refresh()), 'draft' => true])->render();

    expect($html)->toContain('#0c1628')
        ->and($html)->not->toContain('#0f1e36')
        ->and(strpos($html, 'Acme Builders (Pvt) Ltd'))->toBeLessThan(strpos($html, 'Attn: '))
        ->and(strpos($html, '>Site</span>'))->toBeLessThan(strpos($html, '>Type</span>'))
        ->and($html)->toContain('725 sft')
        ->and($html)->toContain('>725<')
        ->and(strpos($html, 'Thank you for the opportunity.'))->toBeGreaterThan(strpos($html, 'Project details'))
        ->and(strpos($html, 'Thank you for the opportunity.'))->toBeLessThan(strpos($html, 'Summary of cost'))
        ->and($html)->toContain('L/S')
        ->and($html)->toContain('sq m')
        ->and($html)->toContain('Terms &amp; conditions');
});

it('uses the espresso premium palette from the v2.1 designs', function () {
    app(QuoteEditor::class)->updateDocumentDesign($this->quote, ['pdf_theme' => 'premium']);

    $html = view('pdf.quote', ['s' => app(QuoteSnapshot::class)->build($this->quote->refresh()), 'draft' => true])->render();

    expect($html)->toContain('/* theme: premium */')->and($html)->toContain('#2b2118');
});

it('labels the quote pill Grand total when adjustments apply', function () {
    $editor = app(QuoteEditor::class);
    $section = $this->quote->sections()->firstOrFail();
    $editor->updateLine($section->items()->firstOrFail(), ['description' => 'Work', 'unit' => 'job', 'qty' => 1, 'rate_minor' => 10000000]);
    $this->quote->refresh()->forceFill(['discount_minor' => 500000, 'total_minor' => 9500000])->save();

    $html = view('pdf.quote', ['s' => app(QuoteSnapshot::class)->build($this->quote->refresh()), 'draft' => true])->render();

    expect($html)->toContain('Grand total (Rs)');
});

it('prints the v2.1 invoice: details box, invoice total, payments stamp, signatures', function () {
    $contact = Contact::factory()->create();
    $invoice = app(CreateManualInvoice::class)->handle($this->owner, [
        'contact_id' => $contact->id, 'business_line' => 'interior',
        'issue_date' => '2026-10-20', 'due_date' => '2026-10-27',
        'discount_minor' => 0, 'tax_bp' => 0,
        'items' => [['description' => 'Wardrobe door repair', 'unit' => 'lumpsum', 'qty' => '1', 'rate_minor' => 1500000]],
        'issue' => true,
    ]);

    $html = view('pdf.invoice', ['s' => $invoice->snapshot])->render();

    expect($html)->toContain('Prepared for')
        ->and($html)->toContain('Invoice details')
        ->and($html)->toContain('Invoice total (Rs)')
        ->and($html)->toContain('Payments received')
        ->and($html)->toContain('No payments received yet.')
        ->and($html)->toContain('Unpaid')
        ->and($html)->toContain('Balance due (Rs)')
        ->and($html)->toContain('Received by')
        ->and($html)->toContain('L/S')
        ->and($html)->not->toContain('Billed to')
        ->and($html)->not->toContain('Amount due');
});

it('prints verified payments and the part-paid / paid stamps', function () {
    $contact = Contact::factory()->create();
    $invoice = app(CreateManualInvoice::class)->handle($this->owner, [
        'contact_id' => $contact->id, 'business_line' => 'interior',
        'issue_date' => '2026-10-20', 'due_date' => '2026-10-27',
        'discount_minor' => 0, 'tax_bp' => 0,
        'items' => [['description' => 'Wardrobe door repair', 'unit' => 'job', 'qty' => '1', 'rate_minor' => 1500000]],
        'issue' => true,
    ]);

    Payment::query()->create([
        'invoice_id' => $invoice->id, 'contact_id' => $contact->id,
        'amount_minor' => 500000, 'received_on' => '2026-10-21', 'method' => 'bank_transfer',
        'status' => 'verified', 'receipt_number' => 'RCP-2026-0001', 'reference' => 'TXN-123',
    ]);
    Payment::query()->create([
        'invoice_id' => $invoice->id, 'contact_id' => $contact->id,
        'amount_minor' => 100000, 'received_on' => '2026-10-22', 'method' => 'cash',
        'status' => 'pending_verification', 'receipt_number' => 'RCP-PENDING', 'reference' => '',
    ]);
    $invoice->forceFill(['paid_minor' => 500000, 'balance_minor' => 1000000])->save();

    $snapshot = app(InvoiceSnapshot::class)->build($invoice->refresh());
    $html = view('pdf.invoice', ['s' => $snapshot])->render();

    expect($snapshot['payments'])->toHaveCount(1)
        ->and($html)->toContain('RCP-2026-0001')
        ->and($html)->not->toContain('RCP-PENDING')
        ->and($html)->toContain('Part paid')
        ->and($html)->not->toContain('No payments received yet.');

    $invoice->forceFill(['paid_minor' => 1500000, 'balance_minor' => 0])->save();
    expect(app(InvoiceSnapshot::class)->build($invoice->refresh())['pay_stamp']['label'])->toBe('Paid');
});
