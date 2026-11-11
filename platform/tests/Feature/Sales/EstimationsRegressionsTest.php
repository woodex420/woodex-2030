<?php

declare(strict_types=1);

use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\ComputeEstimate;
use App\Domain\Sales\Actions\ConvertEstimateToQuote;
use App\Domain\Sales\Actions\CreateQuoteFromEstimate;
use App\Domain\Sales\Actions\ReviewEstimate;
use App\Domain\Sales\Actions\SaveEstimate;
use App\Domain\Sales\Enums\EstimateStatus;
use App\Domain\Sales\Models\CatalogItem;
use App\Domain\Sales\Models\EstimatorRate;
use App\Livewire\Admin\Sales\EstimateBuilder;
use App\Support\Money;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
    $this->actingAs($this->owner);
    $this->rate = EstimatorRate::where('key', 'interior-design')->firstOrFail();
    $this->input = ['title' => 'W audit fixture', 'service_key' => 'interior-design', 'contact_id' => Contact::factory()->create()->id, 'quantity' => '10', 'finish' => 'standard', 'contingency_bp' => 0, 'catalog_lines' => []];
});

it('returns an edited reviewed estimate to draft', function () {
    $e = app(SaveEstimate::class)->handle($this->input, $this->owner);
    app(ReviewEstimate::class)->handle($e, $this->owner);
    $e = app(SaveEstimate::class)->handle([...$this->input, 'quantity' => '20'], $this->owner, $e);
    expect($e->status)->toBe(EstimateStatus::Draft);
});

it('converts using saved prices unless explicitly repriced', function () {
    $e = app(SaveEstimate::class)->handle($this->input, $this->owner);
    $before = $e->computed['rate_minor'];
    $this->rate->update(['standard_minor' => $before * 2]);
    Livewire::test(EstimateBuilder::class, ['estimate' => $e->id])->call('convert')->assertHasNoErrors();
    expect($e->refresh()->computed['rate_minor'])->toBe($before);
});

it('displays saved totals on converted estimates', function () {
    $e = app(SaveEstimate::class)->handle($this->input, $this->owner);
    app(ConvertEstimateToQuote::class)->handle($e, $this->owner);
    $before = $e->computed['total_minor'];
    $this->rate->update(['standard_minor' => $this->rate->standard_minor * 2]);
    $view = Livewire::test(EstimateBuilder::class, ['estimate' => $e->id]);
    expect($view->instance()->summary()['total'])->toBe(Money::ofMinor($before)->format());
});

it('displays catalogue descriptions', function () {
    $item = CatalogItem::where('is_active', true)->firstOrFail();
    Livewire::test(EstimateBuilder::class)->call('addLine')->assertSee(Str::limit($item->description, 60));
});

it('rejects negative quantities rather than making them positive', function () {
    expect(fn () => app(ComputeEstimate::class)->handle($this->rate, '-20', 'standard', [], 0))->toThrow(ValidationException::class);
});

it('routes Furniture enquiries to Furniture quotations', function () {
    $lead = Lead::factory()->create(['contact_id' => $this->input['contact_id'], 'business_line' => 'furniture', 'source' => LeadSource::Estimator, 'service' => 'furniture', 'area_sqft' => 10, 'meta' => ['fields' => ['service' => 'furniture', 'area' => '10', 'finish' => 'standard']]]);
    $quote = app(CreateQuoteFromEstimate::class)->handle($lead, $this->owner);
    expect($quote->business_line->value)->toBe('furniture');
});

it('reuses the quotation on repeated enquiry conversion', function () {
    $lead = Lead::factory()->create(['contact_id' => $this->input['contact_id'], 'source' => LeadSource::Estimator, 'service' => 'interior-design', 'area_sqft' => 10, 'meta' => ['fields' => ['service' => 'interior-design', 'area' => '10', 'finish' => 'standard']]]);
    $first = app(CreateQuoteFromEstimate::class)->handle($lead, $this->owner);
    $again = app(CreateQuoteFromEstimate::class)->handle($lead->refresh(), $this->owner);
    expect($again->id)->toBe($first->id);
});

it('enforces lead visibility at the estimate entry point', function () {
    $sales = $this->userWithRole('sales');
    $lead = Lead::factory()->create(['owner_id' => $this->owner->id, 'service' => 'interior-design', 'area_sqft' => 10]);
    expect($sales->can('view', $lead))->toBeFalse();
    Livewire::actingAs($sales)->test(EstimateBuilder::class, ['lead' => $lead->id])->assertForbidden();
});
