<?php

declare(strict_types=1);

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Models\DocumentDesign;
use App\Domain\Sales\Services\DocumentStyles;
use App\Domain\Sales\Services\QuoteDraftPreview;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuotePdf;
use App\Domain\Sales\Services\QuoteSnapshot;
use App\Livewire\Admin\Sales\QuoteBuilder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    $this->owner = $this->userWithRole('owner');
    $this->actingAs($this->owner);
    $this->client = Contact::factory()->create(['name' => 'Furniture layout client']);
    $this->quote = app(CreateQuote::class)->handle($this->client, BusinessLine::Furniture, $this->owner, title: 'Office furniture');
    // Explicit historical fixture: keep regression coverage for the pre-flexible document.
    $meta = $this->quote->meta;
    unset($meta['document_design']['quote_block_policy']);
    $meta['document_design']['furniture_revision'] = 4;
    $this->quote->forceFill(['meta' => $meta, 'advance_bp' => 5000])->save();
    $this->editor = app(QuoteEditor::class);
    $this->editor->updateLine($this->quote->items()->firstOrFail(), ['product_name' => 'Executive desk', 'description' => 'Approved oak finish', 'rate_minor' => 4750000, 'qty' => '1', 'unit' => 'nos']);
});

it('uses paired client and project cards and a better frozen logo without changing invoices', function () {
    $s = app(QuoteSnapshot::class)->build($this->quote->refresh());
    $html = app(QuotePdf::class)->html($s);
    expect($s['furniture_revision'])->toBe(4)
        ->and($s['document_design']['logo_sha256'])->toBe(hash_file('sha256', public_path('images/furniture-mark-v4.png')))
        ->and(app(DocumentStyles::class)->copy(BusinessLine::Furniture)['furniture_revision'])->toBe(2)
        ->and($html)->toContain('data-furniture-revision="4"', 'class="brand-lockup"', 'Prepared for', 'Project details', 'Office furniture', 'Furniture layout client');
});

it('keeps the four payment conditions and editable default people at the bottom after the contact strip', function () {
    $s = app(QuoteSnapshot::class)->build($this->quote->refresh());
    $html = app(QuotePdf::class)->html($s);
    expect($html)->toContain('50% advance payment with work order.', 'Materials, finish and fabric are subject to client approval.', 'Delivery starts after advance confirmation and material approval.', 'Quotation validity is shown above.', 'Imtiaz Ahmad', 'Director', 'Nabeel Afzal', 'Marketing Manager')
        ->and(strpos($html, '<section class="document-close">'))->toBeLessThan(strpos($html, '<section class="terms">'))
        ->and(strpos($html, '<section class="terms">'))->toBeLessThan(strpos($html, '<table class="signatures">'));
    $page = Livewire::test(QuoteBuilder::class, ['quote' => $this->quote]);
    $page->assertSee('Bottom sections')->assertSee('Automatic', false);
    $markup = $page->html();
    expect(strpos($markup, 'id="details.show_terms"'))->toBeGreaterThan(strpos($markup, 'id="details.terms"'))
        ->and(strpos($markup, 'id="details.show_signatories"'))->toBeGreaterThan(strpos($markup, 'id="details.terms"'));
});

it('hides terms and signatures without deleting their values or changing any amount', function () {
    $before = app(QuoteSnapshot::class)->build($this->quote->refresh());
    $this->editor->updateFurnitureText($this->quote, ['show_terms' => 'no', 'show_signatories' => 'no']);
    $s = app(QuoteSnapshot::class)->build($this->quote->refresh());
    $html = app(QuotePdf::class)->html($s);
    expect($html)->not->toContain('<h3>Terms and conditions</h3>', '<table class="signatures">', 'Imtiaz Ahmad')
        ->and($s['totals'])->toBe($before['totals'])->and($s['terms'])->toBe($before['terms']);
    Livewire::test(QuoteBuilder::class, ['quote' => $this->quote])->assertSee('Bottom sections')->assertSet('details.signatories_text', $s['furniture']['signatories_text']);
    $this->editor->updateFurnitureText($this->quote, ['show_terms' => 'yes', 'show_signatories' => 'yes']);
    expect(app(QuotePdf::class)->html(app(QuoteSnapshot::class)->build($this->quote->refresh())))->toContain('Imtiaz Ahmad', '50% advance payment with work order.');
});

it('retains user-uploaded logos rather than replacing them with the new default mark', function () {
    $bytes = file_get_contents(public_path('images/interior-mark.png'));
    $logo = 'data:image/png;base64,'.base64_encode($bytes);
    DocumentDesign::where('business_line', 'furniture')->update(['logo_data_uri' => $logo, 'logo_sha256' => hash('sha256', $bytes)]);
    $design = app(DocumentStyles::class)->quotationCopy(BusinessLine::Furniture);
    expect($design['logo_data_uri'])->toBe($logo)->and($design['logo_sha256'])->toBe(hash('sha256', $bytes));
});

it('preserves revision three HTML and stored PDFs while explicitly upgrading only editable drafts', function () {
    $meta = $this->quote->meta;
    $meta['document_design']['furniture_revision'] = 3;
    $this->quote->meta = $meta;
    $this->quote->save();
    $old = app(QuoteSnapshot::class)->build($this->quote->refresh());
    $html = app(QuotePdf::class)->html($old);
    $this->editor->updateDetails($this->quote, ['title' => 'Still revision three']);
    expect($this->quote->refresh()->meta['document_design']['furniture_revision'])->toBe(3);
    $this->editor->applyDocumentDesign($this->quote);
    expect(app(QuoteSnapshot::class)->build($this->quote->refresh())['furniture_revision'])->toBe(5)
        ->and(app(QuotePdf::class)->html($old))->toBe($html);
    $this->quote->meta = $meta;
    $this->quote->save();
    $sentHtml = app(QuotePdf::class)->html(app(QuoteSnapshot::class)->build($this->quote->refresh()));
    app(SendQuote::class)->handle($this->quote, $this->owner, 'link', false);
    $version = $this->quote->versions()->firstOrFail();
    $bytes = Storage::disk('local')->get($version->pdf_path);
    expect($version->snapshot['furniture_revision'])->toBe(3)->and(app(QuotePdf::class)->html($version->snapshot))->toBe($sentHtml)
        ->and(hash('sha256', $bytes))->toBe($version->pdf_sha256);
});

it('previews changed advance and signers without modifying the saved document', function () {
    $s = app(QuoteDraftPreview::class)->build($this->quote, ['details' => ['advance' => '37.5', 'signatories_text' => 'Review Person | Director']]);
    expect(app(QuotePdf::class)->html($s))->toContain('37.5% advance payment with work order.', 'Review Person', '17,812.50')
        ->and($this->quote->refresh()->advance_bp)->toBe(5000)
        ->and($this->quote->meta['furniture']['signatories_text'])->not->toContain('Review Person');
});
