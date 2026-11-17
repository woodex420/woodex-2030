<?php

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Services\DocumentStyles;
use App\Domain\Sales\Services\QuoteEditor;
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
    $this->lead = Lead::factory()->create(['owner_id' => $this->owner->id]);
    $this->quote = app(CreateQuote::class)->handle($this->lead->contact, BusinessLine::Interior, $this->owner, $this->lead);
    // Explicit historical fixture: keep regression coverage for the pre-flexible document.
    $meta = $this->quote->meta;
    unset($meta['document_design']['quote_block_policy']);
    $this->quote->forceFill(['meta' => $meta])->save();
    app(QuoteEditor::class)->updateLine($this->quote->items()->firstOrFail(), ['description' => 'Reviewed joinery', 'rate_minor' => 100000]);
    $this->quote->refresh();
});

function builderHtml($quote): string
{
    return view('pdf.quote', ['s' => app(QuoteSnapshot::class)->build($quote->refresh()), 'draft' => true])->render();
}

it('persists per-quotation blocks: hide, move, locked stays visible, reset order', function () {
    $component = Livewire::test(QuoteBuilder::class, ['quote' => $this->quote])
        ->assertSet('quoteBlocks.0.key', 'scope')
        ->assertSet('quoteBlocks.1.key', 'summary')
        ->set('quoteBlocks.0.visible', false)
        ->set('quoteBlocks.3.visible', false);

    $stored = collect($this->quote->refresh()->meta['document_design']['quote_blocks']);
    expect($stored->firstWhere('key', 'scope')['visible'])->toBeFalse()
        ->and($stored->firstWhere('key', 'boq')['visible'])->toBeTrue();

    $component->call('moveQuoteBlock', 5, -1)->call('moveQuoteBlock', 4, -1);
    $keys = collect($this->quote->refresh()->meta['document_design']['quote_blocks'])->pluck('key');
    expect($keys->search('terms'))->toBeLessThan($keys->search('boq'));

    $html = builderHtml($this->quote);
    expect(strpos($html, 'Terms &amp; conditions'))->toBeLessThan(strpos($html, 'Detailed bill of quantities'));

    $component->call('resetQuoteBlocks');
    expect(collect($this->quote->refresh()->meta['document_design']['quote_blocks'])->pluck('key')->all())
        ->toBe(array_keys(DocumentStyles::QUOTE_BLOCKS));
});

it('persists the per-quotation theme and renders it', function () {
    Livewire::test(QuoteBuilder::class, ['quote' => $this->quote])
        ->assertSet('pdfTheme', 'classic')
        ->set('pdfTheme', 'premium');

    expect($this->quote->refresh()->meta['document_design']['pdf_theme'])->toBe('premium')
        ->and(builderHtml($this->quote))->toContain('/* theme: premium */');
});

it('saves type, site and intro line and prints them', function () {
    Livewire::test(QuoteBuilder::class, ['quote' => $this->quote])
        ->set('details.type', 'Renovation')
        ->set('details.site', 'DHA Phase 6, Lahore')
        ->set('details.intro_line', 'Thank you for the opportunity.')
        ->assertHasNoErrors();

    $quote = $this->quote->refresh();
    $html = builderHtml($quote);

    expect($quote->type)->toBe('Renovation')
        ->and($quote->site)->toBe('DHA Phase 6, Lahore')
        ->and($quote->intro_line)->toBe('Thank you for the opportunity.')
        ->and($html)->toContain('Renovation')
        ->and($html)->toContain('DHA Phase 6, Lahore')
        ->and(strpos($html, 'Project details'))->toBeLessThan(strpos($html, 'Thank you for the opportunity.'))
        ->and(strpos($html, 'Thank you for the opportunity.'))->toBeLessThan(strpos($html, 'Summary of cost'));
});

it('prints the client override without touching the contact, and clears it on revert', function () {
    $contactName = $this->quote->contact->name;
    $component = Livewire::test(QuoteBuilder::class, ['quote' => $this->quote])
        ->set('client.name', 'Printed Client Name');

    $snapshot = app(QuoteSnapshot::class)->build($this->quote->refresh());

    expect($snapshot['client']['name'])->toBe('Printed Client Name')
        ->and($this->quote->contact->fresh()->name)->toBe($contactName)
        ->and($this->quote->meta['client_override']['name'])->toBe('Printed Client Name');

    $component->set('client.name', $contactName);

    expect($this->quote->refresh()->meta['client_override'])->not->toHaveKey('name')
        ->and(app(QuoteSnapshot::class)->build($this->quote)['client']['name'])->toBe($contactName);
});

it('shows the persistent side preview with the document itself, and asks it to refresh after edits', function () {
    Livewire::test(QuoteBuilder::class, ['quote' => $this->quote])
        ->assertSee('quotation-workbench')
        ->assertSee('Live preview')
        ->assertSee('Quotation live preview')
        // The pane carries the real document HTML (srcdoc), not a framed PDF.
        ->assertSee('Prepared for')
        ->assertSee('#e9e7e2')
        ->set('details.site', 'Model Town, Lahore')
        ->assertDispatched('preview-refresh');
});

it('refuses design changes once the quotation is sent', function () {
    app(SendQuote::class)->handle($this->quote->refresh(), $this->owner);
    $before = $this->quote->refresh()->meta['document_design']['quote_blocks'];

    Livewire::test(QuoteBuilder::class, ['quote' => $this->quote])
        ->set('pdfTheme', 'premium')
        ->call('moveQuoteBlock', 1, 1);

    expect($this->quote->refresh()->meta['document_design']['quote_blocks'])->toBe($before)
        ->and($this->quote->meta['document_design']['pdf_theme'] ?? 'classic')->toBe('classic');
});
