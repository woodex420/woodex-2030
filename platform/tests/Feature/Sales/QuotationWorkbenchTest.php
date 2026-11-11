<?php

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\SaveQuoteAsTemplate;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Models\QuoteTemplate;
use App\Domain\Sales\Services\QuoteDraftPreview;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuotePdf;
use App\Domain\Sales\Services\QuoteSnapshot;
use App\Domain\Sales\Services\TemplatePackImporter;
use App\Livewire\Admin\Sales\QuoteBuilder;
use App\Settings\SalesSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    $this->owner = $this->userWithRole('owner');
    $this->actingAs($this->owner);
    $lead = Lead::factory()->create(['owner_id' => $this->owner->id]);
    $this->quote = app(CreateQuote::class)->handle($lead->contact, BusinessLine::Interior, $this->owner, $lead);
    $this->item = $this->quote->items()->firstOrFail();
    app(QuoteEditor::class)->updateLine($this->item, ['description' => 'Joinery', 'qty' => '1', 'rate_minor' => 100000]);
    $this->quote->refresh();
});

it('projects unsaved values without writing any database rows', function () {
    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete)/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });
    $snapshot = app(QuoteDraftPreview::class)->build($this->quote, [
        'lines' => [$this->item->id => ['description' => 'Unsaved joinery', 'qty' => '2', 'rate' => '1500.50']],
        'client' => ['name' => 'Unsaved client'],
        'details' => ['scope' => '- Unsaved scope', 'discount_type' => 'percent', 'discount_value' => '10', 'other_charges' => '100', 'tax' => '5'],
    ]);
    expect($snapshot['sections'][0]['items'][0]['line_total_minor'])->toBe(300100)
        ->and($snapshot['client']['name'])->toBe('Unsaved client')
        ->and($snapshot['scope_html'])->toContain('Unsaved scope')
        ->and($snapshot['totals']['subtotal_minor'])->toBe(300100)
        ->and($snapshot['totals']['discount_minor'])->toBe(30010)
        ->and($snapshot['totals']['total_minor'])->toBe(294100)
        ->and($this->item->fresh()->description)->toBe('Joinery')
        ->and($this->item->fresh()->qty)->toBe('1.000')
        ->and($writes)->toBe([]);
});

it('uses the saved rounding setting and excludes optional lines from preview totals', function () {
    $settings = app(SalesSettings::class);
    $settings->round_totals_to_rupee = false;
    $settings->save();
    $snapshot = app(QuoteDraftPreview::class)->build($this->quote, ['lines' => [$this->item->id => ['rate' => '1500.51']]]);
    expect($snapshot['totals']['total_minor'])->toBe(150051)
        ->and(array_sum(array_column($snapshot['milestones'], 'amount_minor')))->toBe(150051);
    $snapshot = app(QuoteDraftPreview::class)->build($this->quote, ['lines' => [$this->item->id => ['is_optional' => true]]]);
    expect($snapshot['totals']['total_minor'])->toBe(0);
});

it('serves a read-only current-input preview and safely rejects invalid numbers', function () {
    $url = route('admin.quotes.preview', $this->quote);
    $this->postJson($url, ['lines' => [$this->item->id => ['qty' => '3']]])
        ->assertOk()->assertJsonPath('error', null)->assertSee('3,000');
    expect($this->item->fresh()->qty)->toBe('1.000');
    $this->postJson($url, ['lines' => [$this->item->id => ['qty' => '-2']]])->assertUnprocessable()->assertJsonPath('html', null);
    $this->postJson($url, ['lines' => [$this->item->id => ['qty' => ['invalid']]]])->assertUnprocessable();
    $this->postJson($url, ['details' => ['valid_until' => 'not-a-date']])->assertUnprocessable();
    $this->postJson($url, ['client' => ['name' => ['malformed']]])->assertUnprocessable();
});

it('ignores foreign line ids and escapes untrusted text', function () {
    $this->postJson(route('admin.quotes.preview', $this->quote), [
        'lines' => [999999 => ['qty' => '999']],
        'client' => ['name' => '<script>alert(1)</script>'],
    ])->assertOk()->assertSee('Joinery')->assertDontSee('<script>alert(1)</script>', false);
    expect($this->quote->fresh()->total_minor)->toBe(100000);
});

it('requires permission to preview other quotations', function () {
    $other = $this->userWithRole('sales');
    $this->actingAs($other)->postJson(route('admin.quotes.preview', $this->quote), [])->assertForbidden();
});

it('keeps sent preview content frozen and ignores attempted overlays', function () {
    $version = app(SendQuote::class)->handle($this->quote, $this->owner);
    $frozen = $version->snapshot;
    $this->quote->contact->update(['name' => 'Changed contact later']);
    $this->postJson(route('admin.quotes.preview', $this->quote), ['client' => ['name' => 'Injected name'], 'quoteLayout' => 'project'])
        ->assertOk()->assertDontSee('Injected name')->assertDontSee('Changed contact later')->assertSee($frozen['client']['name']);
    expect(\App\Support\JsonObjects::canonical($version->fresh()->snapshot))->toBe(\App\Support\JsonObjects::canonical($frozen));
});

it('persists all three layouts separately from colour design and preserves custom blocks', function () {
    $component = Livewire::test(QuoteBuilder::class, ['quote' => $this->quote]);
    $component->assertSee('Quotation live preview')->assertSee('quotation-workbench')
        ->set('quoteLayout', 'single')->set('pdfTheme', 'premium');
    $quote = $this->quote->refresh();
    expect($quote->meta['document_design']['quote_layout'])->toBe('single')
        ->and($quote->meta['document_design']['pdf_theme'])->toBe('premium')
        ->and(collect($quote->meta['document_design']['quote_blocks'])->firstWhere('key', 'summary')['visible'])->toBeFalse();
    $component->set('quoteLayout', 'project');
    $snapshot = app(QuoteSnapshot::class)->build($this->quote->refresh());
    expect($snapshot['quote_layout'])->toBe('project')
        ->and(app(QuotePdf::class)->html($snapshot))->toContain('class="trade-page"');
    $component->set('quoteLayout', 'classic')->set('quoteBlocks.0.visible', false)->set('quoteLayout', 'single');
    expect($this->quote->refresh()->meta['document_design']['quote_blocks'][0]['visible'])->toBeFalse();
});

it('refuses layout edits after sending', function () {
    app(SendQuote::class)->handle($this->quote, $this->owner);
    Livewire::test(QuoteBuilder::class, ['quote' => $this->quote])->set('quoteLayout', 'project');
    expect($this->quote->refresh()->meta['document_design']['quote_layout'] ?? 'classic')->toBe('classic');
});

it('saves and reuses presentation without copying client or company settings into a template', function () {
    app(QuoteEditor::class)->updateDocumentDesign($this->quote, ['quote_layout' => 'project', 'pdf_theme' => 'premium']);
    $template = app(SaveQuoteAsTemplate::class)->handle($this->quote->refresh(), 'Presentation roundtrip');
    expect(array_keys($template->blueprint['presentation']))->toBe(['quote_layout', 'pdf_theme', 'quote_blocks']);
    $created = app(CreateQuote::class)->handle($this->quote->contact, BusinessLine::Interior, $this->owner, template: $template);
    expect($created->meta['document_design']['quote_layout'])->toBe('project')
        ->and($created->meta['document_design']['pdf_theme'])->toBe('premium');
});

it('imports v2.1 presentation and uses the single-table defaults on creation', function () {
    app(TemplatePackImporter::class)->import(['templates' => [[
        'name' => 'Single import proof', 'layout' => 'single', 'design' => 'minimal',
        'sections' => [['name' => 'Supply', 'items' => [['desc' => 'Chair', 'unit' => 'nos', 'qty' => 2, 'rate' => 5000]]]],
    ]]]);
    $template = QuoteTemplate::query()->where('name', 'Single import proof')->firstOrFail();
    $created = app(CreateQuote::class)->handle($this->quote->contact, $template->business_line, $this->owner, template: $template);
    $snapshot = app(QuoteSnapshot::class)->build($created);
    expect($snapshot['quote_layout'])->toBe('single')
        ->and(app(QuotePdf::class)->html($snapshot))->not->toContain('<h2>Summary of cost</h2>');
});

it('renders real PDFs for every v2.1 layout and both business lines', function (string $layout, BusinessLine $line) {
    $quote = app(CreateQuote::class)->handle($this->quote->contact, $line, $this->owner);
    app(QuoteEditor::class)->updateLine($quote->items()->firstOrFail(), ['description' => 'PDF proof', 'rate_minor' => 100000]);
    app(QuoteEditor::class)->updateDocumentDesign($quote, ['quote_layout' => $layout]);
    $snapshot = app(QuoteSnapshot::class)->build($quote->refresh());
    $html = app(QuotePdf::class)->html($snapshot);
    expect($html)->toContain('data-quotation-layout="'.$layout.'"')->toContain($line === BusinessLine::Interior && $layout === 'classic' ? "font-family: 'Jakarta'" : "font-family: 'DM Sans'")
        ->and(strpos($html, 'class="v21-footer"'))->toBeGreaterThan(strpos($html, 'PDF proof'))
        ->and(app(QuotePdf::class)->render($snapshot, draft: true))->toStartWith('%PDF-');
})->with(['classic', 'single', 'project'])->with([BusinessLine::Interior, BusinessLine::Furniture]);
