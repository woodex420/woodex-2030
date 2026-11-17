<?php

use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuoteFromEstimate;
use App\Domain\Sales\Enums\EstimateStatus;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\Estimate;
use App\Domain\Sales\Models\EstimatorRate;
use App\Domain\Sales\Models\Quote;
use App\Livewire\Admin\Crm\LeadShow;
use App\Support\Money;

/*
| QUO-017: the website's cost estimate starts a real quotation.
|
| Somebody who used the estimator has already said what they want, how big it is and which finish — so the
| quotation opens with a priced line instead of an empty page. It opens as a draft, because three form fields are
| not a scope.
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

/** An enquiry that came from the estimator: service, size, finish and the range the website showed. */
function estimatorLead(array $fields = []): Lead
{
    return Lead::factory()->create([
        'owner_id' => test()->sales->id,
        'business_line' => 'interior',
        'source' => 'estimator',
        'meta' => ['form' => 'estimator', 'page' => '/estimator/', 'fields' => array_merge([
            'service' => 'interior-design',
            'area' => '1200',
            'finish' => 'standard',
            'estimate' => 'PKR 2,160,000 to 5,040,000',
        ], $fields)],
    ]);
}

it('reads the estimate off an enquiry that came from the website estimator', function () {
    $read = app(CreateQuoteFromEstimate::class)->read(estimatorLead());

    expect($read['service'])->toBe('interior-design')
        ->and($read['area'])->toBe(1200.0)
        ->and($read['finish'])->toBe('standard')
        ->and($read['estimate'])->toBe('PKR 2,160,000 to 5,040,000')
        ->and($read['rate']?->label)->toBe('Interior design');
});

it('opens a draft quotation priced from the rate book, not from the range the website showed', function () {
    $lead = estimatorLead();

    $quote = app(CreateQuoteFromEstimate::class)->handle($lead, $this->sales);

    expect($quote->status)->toBe(QuoteStatus::Draft)
        ->and($quote->lead_id)->toBe($lead->id)
        ->and($quote->title)->toContain('Interior design');

    $line = $quote->items()->sole();

    // 1,200 sq ft at the standard rate of Rs 2,600.
    expect((float) $line->qty)->toBe(1200.0)
        ->and((string) $line->unit)->toBe('sft')
        ->and($line->rate_minor)->toBe(Money::ofRupees(2_600)->minor)
        ->and($line->line_total_minor)->toBe(Money::ofRupees(3_120_000)->minor)
        ->and($line->description)->toContain('Standard finish')
        ->and($line->description)->toContain('1,200 sft');

    // Whoever picks it up sees both numbers and knows why they differ.
    expect($quote->internal_notes)->toContain('PKR 2,160,000 to 5,040,000')
        ->and($quote->internal_notes)->toContain('Rate from the rate book');
});

it('writes the estimate behind the quotation and links both sides', function () {
    $lead = estimatorLead();

    $quote = app(CreateQuoteFromEstimate::class)->handle($lead, $this->sales);
    $estimate = Estimate::query()->sole();

    expect($estimate->lead_id)->toBe($lead->id)
        ->and($estimate->contact_id)->toBe($lead->contact_id)
        ->and($estimate->quote_id)->toBe($quote->id)
        ->and($estimate->status)->toBe(EstimateStatus::Converted)
        ->and($estimate->inputs['quantity'])->toBe('1200')
        ->and($estimate->inputs['finish'])->toBe('standard')
        ->and($quote->refresh()->estimate_id)->toBe($estimate->id)
        // The website's range is kept on the estimate as its assumption, not invented into the price.
        ->and($estimate->assumptions)->toContain('PKR 2,160,000 to 5,040,000')
        // The quotation still tells the salesperson what they are holding.
        ->and($quote->internal_notes)->toContain($estimate->number);
});

it('prices the finish the client chose', function () {
    $quote = app(CreateQuoteFromEstimate::class)->handle(estimatorLead(['finish' => 'premium']), $this->sales);

    expect($quote->items()->sole()->rate_minor)->toBe(Money::ofRupees(4_200)->minor);

    $quote = app(CreateQuoteFromEstimate::class)->handle(estimatorLead(['finish' => 'essential']), $this->sales);

    expect($quote->items()->sole()->rate_minor)->toBe(Money::ofRupees(1_800)->minor);
});

it('is offered only when the enquiry really carries an estimate', function () {
    $creator = app(CreateQuoteFromEstimate::class);

    expect($creator->available(estimatorLead()))->toBeTrue()
        ->and($creator->available(estimatorLead(['service' => 'something-we-do-not-price'])))->toBeFalse()
        ->and($creator->available(estimatorLead(['area' => ''])))->toBeFalse()
        ->and($creator->available(Lead::factory()->create(['owner_id' => $this->sales->id, 'business_line' => 'interior', 'source' => 'website_form'])))->toBeFalse();
});

it('starts the quotation from the enquiry page and opens it, in one click', function () {
    $lead = estimatorLead();

    Livewire\Livewire::actingAs($this->sales)
        ->test(LeadShow::class, ['lead' => $lead])
        ->call('startQuoteFromEstimate')
        ->assertRedirect(route('admin.quotes.show', ['quote' => Quote::query()->sole()->id]));

    expect(Quote::query()->sole()->lead_id)->toBe($lead->id);
});

it('says so instead of failing when there is nothing to price', function () {
    // Source pinned: an unpinned factory lead can roll source=estimator with a rate-book service,
    // which the pre-meta column fallback would then price — the premise must hold on every run.
    $lead = Lead::factory()->create(['owner_id' => $this->sales->id, 'business_line' => 'interior', 'source' => 'website_form', 'meta' => null]);

    Livewire\Livewire::actingAs($this->sales)
        ->test(LeadShow::class, ['lead' => $lead])
        ->call('startQuoteFromEstimate')
        ->assertHasErrors('estimate');

    expect(Quote::query()->count())->toBe(0);
});
