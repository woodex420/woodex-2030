<?php

use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Enums\EstimateStatus;
use App\Domain\Sales\Models\Estimate;
use App\Domain\Sales\Models\EstimatorRate;
use App\Support\Money;

/*
| Slice C catch-up: estimator enquiries that arrived before the listener existed get the same draft
| estimate every new enquiry gets — once, and never a duplicate.
*/

beforeEach(function () {
    $this->sales = $this->userWithRole('sales');

    EstimatorRate::query()->updateOrCreate(['key' => 'interior-design'], [
        'label' => 'Interior design',
        'unit' => 'sft',
        'unit_label' => 'Area (sq ft)',
        'essential_minor' => Money::ofRupees(1_800)->minor,
        'standard_minor' => Money::ofRupees(2_600)->minor,
        'premium_minor' => Money::ofRupees(4_200)->minor,
        'is_active' => true,
        'position' => 1,
    ]);
});

function oldEstimatorLead(array $overrides = []): Lead
{
    return Lead::factory()->create(array_merge([
        'owner_id' => test()->sales->id,
        'source' => 'estimator',
        'service' => 'interior-design',
        'area_sqft' => 900,
        'meta' => ['form' => 'estimator', 'page' => '/estimator/'],
    ], $overrides));
}

it('writes a draft estimate for an enquiry that predates the listener', function () {
    $lead = oldEstimatorLead();

    expect($this->artisan('estimates:backfill'))->toBe(0);

    $estimate = Estimate::query()->sole();

    expect($estimate->lead_id)->toBe($lead->id)
        ->and($estimate->status)->toBe(EstimateStatus::Draft)
        ->and($estimate->service_key)->toBe('interior-design')
        ->and($estimate->inputs['quantity'])->toBe('900')
        // Falls back to the lead's own size, because this enquiry has no meta fields.
        ->and($estimate->computed['headline_minor'])->toBe(Money::ofRupees(2_340_000)->minor);
});

it('does nothing on a dry run', function () {
    oldEstimatorLead();

    expect($this->artisan('estimates:backfill', ['--dry-run' => true]))->toBe(0);

    expect(Estimate::query()->count())->toBe(0);
});

it('is safe to run twice', function () {
    oldEstimatorLead();

    expect($this->artisan('estimates:backfill'))->toBe(0);
    expect($this->artisan('estimates:backfill'))->toBe(0);

    expect(Estimate::query()->count())->toBe(1);
});

it('leaves an enquiry whose service is no longer in the rate book for a person', function () {
    oldEstimatorLead(['service' => 'roof-thatching']);

    expect($this->artisan('estimates:backfill'))->toBe(0);

    expect(Estimate::query()->count())->toBe(0);
});
