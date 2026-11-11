<?php

declare(strict_types=1);

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Enums\DiscountType;
use App\Domain\Sales\Exceptions\QuoteLocked;
use App\Domain\Sales\Services\DocumentStyles;
use App\Domain\Sales\Services\QuoteDraftPreview;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuotePdf;
use App\Domain\Sales\Services\QuoteSnapshot;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    $this->owner = $this->userWithRole('owner');
    $this->actingAs($this->owner);
    $this->client = Contact::factory()->create(['name' => 'Native preview client']);
    $this->quote = app(CreateQuote::class)->handle($this->client, BusinessLine::Interior, $this->owner);
    // Explicit historical fixture: keep regression coverage for the pre-flexible document.
    $meta = $this->quote->meta;
    unset($meta['document_design']['quote_block_policy']);
    $meta['document_design']['interior_revision'] = 2;
    $this->quote->forceFill(['meta' => $meta])->save();
    $this->editor = app(QuoteEditor::class);
    $this->editor->updateLine($this->quote->items()->firstOrFail(), ['description' => 'Approved joinery', 'qty' => '2', 'unit' => 'sft', 'rate_minor' => 500000]);
});

it('uses the approved quotation-only Interior design with local fonts and honest native metadata', function () {
    $s = app(QuoteSnapshot::class)->build($this->quote->refresh());
    $html = app(QuotePdf::class)->html($s);
    expect($s['interior_revision'])->toBe(2)
        ->and(app(DocumentStyles::class)->copy(BusinessLine::Interior))->not->toHaveKey('interior_revision')
        ->and($html)->toContain('data-interior-revision="2"', 'QUOTATION ', 'BOQ', 'Prepared for', 'Project details', 'Measured BOQ area', 'Phase / Notes', 'NET TOTAL (PKR)', "font-family: 'Jakarta'", 'data:font/ttf;base64,', 'Native preview client')
        ->not->toContain('Project Code', 'Covered Area', 'Tariq', 'cdn.tailwindcss.com', 'fonts.googleapis.com', 'contenteditable');
    expect(app(QuotePdf::class)->render($s))->toStartWith('%PDF-');
});

it('calculates all twelve reference lines rather than either inconsistent source aggregate', function () {
    $first = $this->quote->sections()->firstOrFail();
    $groups = [[[1250, 6000], [1, 7000000]], [[340, 45000], [2, 4250000], [850, 9500], [1150, 22000], [1400, 14500], [6, 2400000]], [[1, 11500000], [28, 250000], [1, 7500000]], [[2200, 7500]]];
    foreach ($groups as $i => $lines) {
        $section = $i === 0 ? $first : $this->editor->addSection($this->quote, 'Section '.($i + 1));
        $this->editor->updateSection($section, ['name' => 'Section '.($i + 1), 'note' => 'Approved phase label '.($i + 1)]);
        foreach ($lines as $j => [$qty, $rate]) {
            $attrs = ['description' => 'Measured work '.($i + 1).'.'.($j + 1), 'qty' => (string) $qty, 'rate_minor' => $rate, 'unit' => 'nos'];
            if ($j === 0) {
                $this->editor->updateLine($section->items()->firstOrFail(), $attrs);
            } else {
                $this->editor->addLine($section, $attrs);
            }
        }
    }
    $s = app(QuoteSnapshot::class)->build($this->quote->refresh());
    expect(array_column($s['sections'], 'subtotal_minor'))->toBe([14500000, 91875000, 26000000, 16500000])
        ->and($s['totals']['subtotal_minor'])->toBe(148875000)->and($s['totals']['total_minor'])->toBe(148875000);
    $html = app(QuotePdf::class)->html($s);
    expect($html)->toContain('1,488,750', '918,750', 'Approved phase label 4', 'Measured work 4.1')->not->toContain('1,325,000', '1,435,750');
});

it('discloses optional lines and native financial adjustments instead of treating budget as net', function () {
    $this->editor->addLine($this->quote->sections()->firstOrFail(), ['description' => 'Optional excluded work', 'qty' => '1', 'rate_minor' => 5000000, 'unit' => 'job', 'is_optional' => true]);
    $this->editor->updateDetails($this->quote, ['discount_type' => DiscountType::Percent, 'discount_value' => 1000, 'other_charges_minor' => 200000, 'tax_bp' => 500]);
    $s = app(QuoteSnapshot::class)->build($this->quote->refresh());
    expect($s['totals']['subtotal_minor'])->toBe(1000000)->and($s['totals']['total_minor'])->toBe(1155000);
    expect(app(QuotePdf::class)->html($s))->toContain('optional, not in total', '10,000', '11,550', 'Discount (10%)', 'Total tax (5%)');
});

it('keeps alternate layouts and historical Interior snapshots on their original renderer', function () {
    $old = app(QuoteSnapshot::class)->build($this->quote->refresh());
    unset($old['interior_revision'], $old['document_design']['interior_revision']);
    $html = app(QuotePdf::class)->html($old);
    expect($html)->not->toContain('data-interior-revision="2"');
    $this->editor->applyDocumentDesign($this->quote);
    expect(app(QuotePdf::class)->html($old))->toBe($html);
    foreach (['single', 'project'] as $layout) {
        $this->editor->updateDocumentDesign($this->quote, ['quote_layout' => $layout]);
        expect(app(QuotePdf::class)->html(app(QuoteSnapshot::class)->build($this->quote->refresh())))->toContain('data-quotation-layout="'.$layout.'"')->not->toContain('data-interior-revision="2"');
    }
});

it('shows unsaved Interior values in the approved preview without writing them', function () {
    $item = $this->quote->items()->firstOrFail();
    $s = app(QuoteDraftPreview::class)->build($this->quote, ['lines' => [$item->id => ['description' => 'Unsaved revised joinery', 'rate' => '7500']]]);
    expect(app(QuotePdf::class)->html($s))->toContain('data-interior-revision="2"', 'Unsaved revised joinery', '15,000')
        ->and($item->refresh()->description)->toBe('Approved joinery')->and($item->rate_minor)->toBe(500000);
});

it('does not silently upgrade an old draft and preserves its sent snapshot and stored bytes', function () {
    $meta = $this->quote->meta;
    unset($meta['document_design']['interior_revision']);
    $this->quote->meta = $meta;
    $this->quote->save();
    $this->editor->updateDetails($this->quote, ['title' => 'Old style remains']);
    expect(app(QuoteSnapshot::class)->build($this->quote->refresh())['interior_revision'])->toBe(1);
    app(SendQuote::class)->handle($this->quote, $this->owner, 'link', false);
    $version = $this->quote->versions()->firstOrFail();
    $html = app(QuotePdf::class)->html($version->snapshot);
    $bytes = Storage::disk('local')->get($version->pdf_path);
    expect(fn () => $this->editor->applyDocumentDesign($this->quote->refresh()))->toThrow(QuoteLocked::class);
    expect(app(QuotePdf::class)->html($version->snapshot))->toBe($html)->and(hash('sha256', $bytes))->toBe($version->pdf_sha256)
        ->and(Storage::disk('local')->get($version->pdf_path))->toBe($bytes);
});
