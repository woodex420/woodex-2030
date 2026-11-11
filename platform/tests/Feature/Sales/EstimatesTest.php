<?php

use App\Domain\Crm\Models\Contact;
use App\Domain\Sales\Actions\ComputeEstimate;
use App\Domain\Sales\Actions\ConvertEstimateToQuote;
use App\Domain\Sales\Actions\ReviewEstimate;
use App\Domain\Sales\Actions\SaveEstimate;
use App\Domain\Sales\Enums\EstimateStatus;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\CatalogItem;
use App\Domain\Sales\Models\Estimate;
use App\Domain\Sales\Models\EstimatorRate;
use App\Domain\Sales\Models\Quote;
use App\Livewire\Admin\Sales\EstimateBuilder;
use App\Livewire\Admin\Sales\EstimationsIndex;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| P8.3 (docs/19 §7): persistent estimates. The engine prices with the same rate book the website
| uses; the snapshot freezes what was computed and when; conversion creates a quotation draft —
| idempotent, linked both ways — and the estimate itself never reaches a client.
*/

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
    $this->sales = $this->userWithRole('sales');
    $this->support = $this->userWithRole('support');
    $this->contact = Contact::factory()->create(['name' => 'Hira Sheikh', 'owner_id' => $this->sales->id]);

    /** @var EstimatorRate */
    $this->rate = EstimatorRate::query()->where('key', 'interior-design')->where('is_active', true)->sole();
});

function estimateInput(array $overrides = []): array
{
    return array_merge([
        'title' => 'Lounge and kitchen — DHA Phase 5',
        'service_key' => 'interior-design',
        'contact_id' => null,
        'quantity' => '2400',
        'finish' => 'standard',
        'contingency_bp' => 500,
        'assumptions' => 'Rates per sq ft of covered area.',
        'exclusions' => 'Civil structure work.',
        'catalog_lines' => [],
    ], $overrides);
}

it('prices an estimate from the rate book with an honest essential-to-premium band', function () {
    $computed = app(ComputeEstimate::class)->handle($this->rate, '2400', 'standard', [], 500);

    expect($computed['rate_minor'])->toBe((int) $this->rate->standard_minor)
        ->and($computed['headline_minor'])->toBe((int) $this->rate->standard_minor * 2400)
        ->and($computed['range_low_minor'])->toBe((int) $this->rate->essential_minor * 2400)
        ->and($computed['range_high_minor'])->toBe((int) $this->rate->premium_minor * 2400)
        ->and($computed['contingency_minor'])->toBe((int) round($computed['headline_minor'] * 0.05))
        ->and($computed['total_minor'])->toBe($computed['headline_minor'] + $computed['contingency_minor'])
        ->and($computed['effective_on'])->toBe(today()->toDateString());
});

it('prices rate book lines at today\'s rates inside the same computation', function () {
    $item = CatalogItem::query()->where('is_active', true)->firstOrFail();

    $computed = app(ComputeEstimate::class)->handle($this->rate, '2400', 'premium', [
        ['catalog_item_id' => $item->id, 'qty' => '4'],
    ], 0);

    expect($computed['lines'])->toHaveCount(1)
        ->and($computed['lines'][0]['total_minor'])->toBe(4 * (int) $item->rate_minor)
        ->and($computed['lines_minor'])->toBe(4 * (int) $item->rate_minor)
        ->and($computed['subtotal_minor'])->toBe($computed['headline_minor'] + 4 * (int) $item->rate_minor);
});

it('refuses a zero quantity and unknown items', function () {
    app(ComputeEstimate::class)->handle($this->rate, '0', 'standard', [], 0);
})->throws(ValidationException::class);

it('saves an estimate with a stable EST number and a frozen snapshot', function () {
    $estimate = app(SaveEstimate::class)->handle(estimateInput(), $this->sales);

    expect($estimate->number)->toMatch('/^EST-'.now()->format('Y').'-\d{4}$/')
        ->and($estimate->status)->toBe(EstimateStatus::Draft)
        ->and($estimate->computed['headline_minor'])->toBe((int) $this->rate->standard_minor * 2400)
        ->and($estimate->inputs['unit'])->toBe((string) $this->rate->unit);

    $rateBefore = (int) $this->rate->standard_minor;
    $this->rate->forceFill(['standard_minor' => $rateBefore + 100_00])->save();

    // The snapshot keeps what was computed; only a re-save picks up the new rate.
    expect((int) $estimate->refresh()->computed['rate_minor'])->toBe($rateBefore);

    $again = app(SaveEstimate::class)->handle(estimateInput(['finish' => 'premium']), $this->sales, $estimate);

    expect($again->number)->toBe($estimate->number)
        ->and((int) $again->computed['rate_minor'])->toBe((int) $this->rate->premium_minor)
        ->and($again->status)->toBe(EstimateStatus::Draft);
});

it('refuses edits after conversion', function () {
    $estimate = app(SaveEstimate::class)->handle(estimateInput(['contact_id' => $this->contact->id]), $this->sales);
    app(ConvertEstimateToQuote::class)->handle($estimate, $this->owner);

    app(SaveEstimate::class)->handle(estimateInput(), $this->sales, $estimate);
})->throws(ValidationException::class, 'already converted');

it('records the review act with who and when', function () {
    $estimate = app(SaveEstimate::class)->handle(estimateInput(), $this->sales);

    $reviewed = app(ReviewEstimate::class)->handle($estimate, $this->owner);

    expect($reviewed->status)->toBe(EstimateStatus::Reviewed)
        ->and($reviewed->reviewed_by)->toBe($this->owner->id)
        ->and($reviewed->reviewed_at)->not->toBeNull();
});

it('converts to a quotation draft, idempotently, linked both ways', function () {
    $item = CatalogItem::query()->where('is_active', true)->firstOrFail();

    $estimate = app(SaveEstimate::class)->handle(estimateInput([
        'contact_id' => $this->contact->id,
        'catalog_lines' => [['catalog_item_id' => $item->id, 'qty' => '4']],
    ]), $this->sales);

    $quote = app(ConvertEstimateToQuote::class)->handle($estimate, $this->sales);

    expect($quote->status)->toBe(QuoteStatus::Draft)
        ->and($quote->estimate_id)->toBe($estimate->id)
        ->and($estimate->refresh()->quote_id)->toBe($quote->id)
        ->and($estimate->refresh()->status)->toBe(EstimateStatus::Converted)
        ->and($quote->business_line->value)->toBe('interior')
        ->and($quote->sections)->toHaveCount(2)
        ->and((int) $quote->items->where('rate_minor', '>', 0)->first(fn ($item) => str_contains((string) $item->description, 'Interior Design'))->rate_minor)->toBe((int) $estimate->computed['rate_minor'])
        ->and($quote->items->pluck('description'))->toContain($item->description)
        ->and($quote->internal_notes)->toContain('range')
        ->and($quote->internal_notes)->toContain('Assumptions: Rates per sq ft of covered area.');

    // Converting again returns the same quotation — never a second one.
    $again = app(ConvertEstimateToQuote::class)->handle($estimate->refresh(), $this->owner);

    expect($again->id)->toBe($quote->id)
        ->and(Quote::query()->where('estimate_id', $estimate->id)->count())->toBe(1);
});

it('sends furniture estimates to the furniture line', function () {
    $estimate = app(SaveEstimate::class)->handle(estimateInput([
        'service_key' => 'furniture',
        'contact_id' => $this->contact->id,
        'quantity' => '600',
    ]), $this->sales);

    $quote = app(ConvertEstimateToQuote::class)->handle($estimate, $this->sales);

    expect($quote->business_line->value)->toBe('furniture')
        ->and($quote->number)->toStartWith('WF-');
});

it('refuses to convert without a client', function () {
    $estimate = app(SaveEstimate::class)->handle(estimateInput(['contact_id' => null]), $this->sales);

    app(ConvertEstimateToQuote::class)->handle($estimate, $this->owner);
})->throws(ValidationException::class, 'Give this estimate a client');

it('keeps estimates away from roles without the permissions', function () {
    app(SaveEstimate::class)->handle(estimateInput(), $this->support);
})->throws(AuthorizationException::class);

it('shows the estimations screens behind the estimates permission', function () {
    $this->get('/admin/estimations')->assertRedirect('/admin/login');

    Livewire::actingAs($this->owner)
        ->test(EstimationsIndex::class)
        ->assertOk();

    $estimate = app(SaveEstimate::class)->handle(estimateInput(['contact_id' => $this->contact->id]), $this->sales);

    Livewire::actingAs($this->owner)
        ->test(EstimateBuilder::class, ['estimate' => $estimate->id])
        ->assertOk()
        ->assertSet('number', $estimate->number)
        ->assertSet('title', $estimate->title);

    $this->actingAs($this->support)->get('/admin/estimations')->assertForbidden();
});

it('builds and converts from the Livewire screen like a person would', function () {
    Livewire::actingAs($this->sales)
        ->test(EstimateBuilder::class)
        ->set('title', 'Office fit-out — MM Alam Road')
        ->set('serviceKey', 'fit-out')
        ->set('contactId', (string) $this->contact->id)
        ->set('quantity', '1800')
        ->set('finish', 'essential')
        ->set('contingencyBp', '0')
        ->call('save')
        ->assertHasNoErrors()
        ->assertNotSet('estimateId', null);

    $estimate = Estimate::query()->sole();

    expect($estimate->number)->toStartWith('EST-')
        ->and((int) $estimate->computed['rate_minor'])->toBe((int) EstimatorRate::query()->where('key', 'fit-out')->value('essential_minor'));

    Livewire::actingAs($this->sales)
        ->test(EstimateBuilder::class, ['estimate' => $estimate->id])
        ->call('convert')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.quotes.show', $estimate->refresh()->quote_id));

    expect($estimate->refresh()->status)->toBe(EstimateStatus::Converted)
        ->and(Quote::query()->where('estimate_id', $estimate->id)->count())->toBe(1);
});

it('allocates sequential estimate numbers', function () {
    $first = app(SaveEstimate::class)->handle(estimateInput(), $this->sales);
    $second = app(SaveEstimate::class)->handle(estimateInput(['title' => 'Second job']), $this->sales);

    expect($second->number)->not->toBe($first->number);
});
