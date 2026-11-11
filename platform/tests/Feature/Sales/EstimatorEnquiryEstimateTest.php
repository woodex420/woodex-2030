<?php

use App\Domain\Crm\Actions\CaptureLead;
use App\Domain\Crm\Data\LeadInput;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuoteFromEstimate;
use App\Domain\Sales\Enums\EstimateStatus;
use App\Domain\Sales\Models\Estimate;
use App\Domain\Sales\Models\EstimatorRate;
use App\Domain\Sales\Services\EstimatorLeadDetails;
use App\Support\Money;

/*
| Slice C — one pricing path.
|
| An estimator enquiry already carries the three facts the rate book needs. It now writes its draft
| estimate on arrival, so the Estimations screen is the pipeline rather than an empty room, and the
| quotation started from that enquiry converts *that* estimate instead of writing a second one.
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

function captureEstimatorEnquiry(array $fields = [], ?string $phone = '0322 4000768'): Lead
{
    $result = app(CaptureLead::class)->handle(new LeadInput(
        name: 'Ayesha Khan',
        phone: $phone,
        email: null,
        source: LeadSource::Estimator,
        businessLine: BusinessLine::Interior,
        city: 'Lahore',
        ownerId: test()->sales->id,
        meta: ['form' => 'estimator', 'page' => '/estimator/', 'fields' => array_merge([
            'service' => 'interior-design',
            'area' => '1200',
            'finish' => 'standard',
            'estimate' => 'PKR 2,160,000 to 5,040,000',
        ], $fields)],
    ));

    return $result->lead->refresh();
}

it('writes the draft estimate when an estimator enquiry arrives', function () {
    $lead = captureEstimatorEnquiry();

    $estimate = Estimate::query()->sole();

    expect($estimate->lead_id)->toBe($lead->id)
        ->and($estimate->contact_id)->toBe($lead->contact_id)
        ->and($estimate->status)->toBe(EstimateStatus::Draft)
        ->and($estimate->service_key)->toBe('interior-design')
        ->and($estimate->inputs['quantity'])->toBe('1200')
        ->and($estimate->inputs['finish'])->toBe('standard')
        // 1,200 sq ft at the standard rate of Rs 2,600 — the same engine the builder and the quote use.
        ->and($estimate->computed['headline_minor'])->toBe(Money::ofRupees(3_120_000)->minor)
        ->and($estimate->created_by)->toBe($this->sales->id)
        // The website's range is kept as what the client was told, not as the price.
        ->and($estimate->assumptions)->toContain('PKR 2,160,000 to 5,040,000');
});

it('keeps one draft when the same enquiry is submitted again', function () {
    captureEstimatorEnquiry();
    captureEstimatorEnquiry();

    expect(Lead::query()->count())->toBe(1)
        ->and(Estimate::query()->count())->toBe(1);
});

it('leaves a form enquiry without an estimate', function () {
    app(CaptureLead::class)->handle(new LeadInput(
        name: 'Bilal Ahmed',
        phone: '0300 1234567',
        email: null,
        source: LeadSource::WebsiteForm,
        businessLine: BusinessLine::Interior,
        city: 'Lahore',
    ));

    expect(Estimate::query()->count())->toBe(0);
});

it('leaves an enquiry for a service the rate book does not list without an estimate', function () {
    captureEstimatorEnquiry(['service' => 'roof-thatching']);

    expect(Estimate::query()->count())->toBe(0);
});

it('converts the draft the enquiry already had instead of writing another one', function () {
    $lead = captureEstimatorEnquiry();
    $draft = Estimate::query()->sole();

    $quote = app(CreateQuoteFromEstimate::class)->handle($lead, $this->sales);

    expect(Estimate::query()->count())->toBe(1)
        ->and($draft->refresh()->status)->toBe(EstimateStatus::Converted)
        ->and($draft->quote_id)->toBe($quote->id)
        ->and($quote->refresh()->estimate_id)->toBe($draft->id);
});

it('reads the estimator service column when real bridge extras omit only the service', function () {
    $fields = ['area' => '1200', 'finish' => 'premium', 'estimate' => 'Visitor range'];
    $lead = new Lead([
        'source' => LeadSource::Estimator, 'service' => 'interior-design',
        'meta' => ['fields' => $fields],
    ]);

    $read = app(EstimatorLeadDetails::class)->read($lead);

    expect($read['service'])->toBe('interior-design')
        ->and($read['rate']?->key)->toBe('interior-design')
        ->and($read['area'])->toBe(1200.0)
        ->and($read['finish'])->toBe('premium')
        ->and($lead->meta['fields'])->toBe($fields);
});

it('preserves explicit metadata services rather than substituting the column', function (?string $service) {
    $lead = new Lead([
        'source' => LeadSource::Estimator, 'service' => 'interior-design',
        'meta' => ['fields' => ['service' => $service, 'area' => '1200', 'finish' => 'standard']],
    ]);

    $read = app(EstimatorLeadDetails::class)->read($lead);

    expect($read['service'])->toBe(trim((string) $service))
        ->and($read['rate'])->toBeNull()
        ->and(app(EstimatorLeadDetails::class)->available($lead))->toBeFalse();
})->with(['unknown' => 'roof-thatching', 'explicit blank' => '', 'explicit null' => null]);

it('honours a different active explicit metadata service', function () {
    $lead = new Lead([
        'source' => LeadSource::Estimator, 'service' => 'renovation',
        'meta' => ['estimate' => ['service' => 'interior-design', 'area' => '1200']],
    ]);

    expect(app(EstimatorLeadDetails::class)->read($lead)['rate']?->key)->toBe('interior-design');
});

it('does not use service columns for non-estimator leads with partial fields', function () {
    $lead = new Lead([
        'source' => LeadSource::WebsiteForm, 'service' => 'interior-design', 'area_sqft' => 1200,
        'meta' => ['fields' => ['area' => '1200', 'finish' => 'standard']],
    ]);

    expect(app(EstimatorLeadDetails::class)->read($lead)['service'])->toBe('')
        ->and(app(EstimatorLeadDetails::class)->available($lead))->toBeFalse();
});

it('preserves legacy column fallback but never fills missing area in partial metadata', function () {
    $lead = new Lead([
        'source' => LeadSource::Estimator, 'service' => 'interior-design', 'area_sqft' => 1200,
        'meta' => [],
    ]);
    expect(app(EstimatorLeadDetails::class)->available($lead))->toBeTrue();

    $lead->meta = ['fields' => ['finish' => 'standard']];
    expect(app(EstimatorLeadDetails::class)->read($lead)['area'])->toBe(0.0)
        ->and(app(EstimatorLeadDetails::class)->available($lead))->toBeFalse();
});

it('does not replace an inactive explicit metadata rate with the active service column', function () {
    EstimatorRate::query()->where('key', 'renovation')->update(['is_active' => false]);
    $lead = new Lead([
        'source' => LeadSource::Estimator, 'service' => 'interior-design',
        'meta' => ['fields' => ['service' => 'renovation', 'area' => '1200']],
    ]);

    expect(app(EstimatorLeadDetails::class)->read($lead)['rate'])->toBeNull()
        ->and(app(EstimatorLeadDetails::class)->available($lead))->toBeFalse();
});
