<?php

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Enums\MilestonePlanKey;
use App\Domain\Sales\Enums\TemplateKind;
use App\Domain\Sales\Models\QuoteTemplate;
use App\Domain\Sales\Services\TemplatePackImporter;
use App\Livewire\Admin\Sales\QuoteTemplates;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
    $this->actingAs($this->owner);
});

it('imports the starter pack mapped like the seeder (kinds, lines, rupee rates)', function () {
    QuoteTemplate::query()->delete();

    $result = app(TemplatePackImporter::class)->import(TemplatePackImporter::starterContents());

    expect($result['imported'])->toHaveCount(16)
        ->and(QuoteTemplate::query()->count())->toBe(16);

    $renovation = QuoteTemplate::query()->where('name', 'Renovation')->firstOrFail();
    expect($renovation->kind)->toBe(TemplateKind::Execution)
        ->and($renovation->business_line)->toBe(BusinessLine::Interior)
        ->and($renovation->milestone_plan)->toBe(MilestonePlanKey::Execution)
        ->and($renovation->sectionCount())->toBe(10)
        ->and($renovation->itemCount())->toBe(42)
        ->and($renovation->blueprint['sections'][0]['items'][0]['rate_minor'])->toBe(5000000)
        ->and($renovation->terms)->toContain('50% advance');

    expect(QuoteTemplate::query()->where('name', 'Furniture')->firstOrFail()->business_line)->toBe(BusinessLine::Furniture);

    $design = QuoteTemplate::query()->where('name', 'Interior Designing')->firstOrFail();
    expect($design->kind)->toBe(TemplateKind::Design)
        ->and($design->milestone_plan)->toBe(MilestonePlanKey::Design);

    expect(QuoteTemplate::query()->where('name', 'Renovation – Scope of Work')->firstOrFail()->kind)->toBe(TemplateKind::Scope);
});

it('imports nothing when the starter pack is already complete', function () {
    $result = app(TemplatePackImporter::class)->import(TemplatePackImporter::starterContents());

    expect($result['imported'])->toBe([])->and($result['skipped'])->not->toBe([]);
});

it('imports an uploaded v2.1 template pack through the templates screen', function () {
    $pack = UploadedFile::fake()->createWithContent('quotation-templates.json', json_encode(['templates' => [[
        'name' => 'Test Pack Template', 'kind' => 'design', 'description' => 'From a pack.',
        'terms' => '75% advance with order.',
        'sections' => [['name' => 'Concept', 'items' => [
            ['desc' => 'Mood board', 'qty' => 2, 'unit' => 'job', 'rate' => 25000],
            ['desc' => 'Odd unit line', 'qty' => 1, 'unit' => 'batch', 'rate' => 100],
        ]]],
    ]]]));

    Livewire::test(QuoteTemplates::class)
        ->set('importFile', $pack)
        ->call('importTemplate')
        ->assertHasNoErrors();

    $template = QuoteTemplate::query()->where('name', 'Test Pack Template')->firstOrFail();
    expect($template->kind)->toBe(TemplateKind::Design)
        ->and($template->milestone_plan)->toBe(MilestonePlanKey::Design)
        ->and($template->blueprint['sections'][0]['items'][0]['rate_minor'])->toBe(2500000)
        ->and($template->blueprint['sections'][0]['items'][1]['unit'])->toBe('job');
});

it('builds a priced quotation from a starter template', function () {
    $template = QuoteTemplate::query()->where('name', 'Renovation')->firstOrFail();
    $lead = Lead::factory()->create(['owner_id' => $this->owner->id]);

    $quote = app(CreateQuote::class)->handle($lead->contact, BusinessLine::Interior, $this->owner, $lead, $template);

    expect($quote->sections()->count())->toBe(10)
        ->and($quote->items()->count())->toBe(42)
        ->and($quote->total_minor)->toBeGreaterThan(0)
        ->and($quote->terms)->toContain('50% advance');
});
