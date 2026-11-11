<?php

declare(strict_types=1);

use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\ComputeEstimate;
use App\Domain\Sales\Actions\CreateQuoteFromEstimate;
use App\Domain\Sales\Actions\ReviewEstimate;
use App\Domain\Sales\Actions\SaveEstimate;
use App\Domain\Sales\Enums\EstimateStatus;
use App\Domain\Sales\Models\Estimate;
use App\Domain\Sales\Models\EstimatorRate;
use App\Domain\Sales\Services\EstimatorRateBooks;
use App\Domain\Website\Studio\RateBook;
use App\Livewire\Admin\Sales\EstimateBuilder;
use App\Livewire\Admin\Sales\EstimationsIndex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
    $this->actingAs($this->owner);
    File::ensureDirectoryExists(storage_path('framework/testing'));
    $path = storage_path('framework/testing/w-rates.js');
    File::copy(public_path('assets/js/estimator-rates.js'), $path);
    config(['website.rate_book_path' => $path]);
    $this->books = app(EstimatorRateBooks::class);
    $this->input = ['title' => 'Hub fixture', 'service_key' => 'interior-design', 'contact_id' => Contact::factory()->create()->id, 'quantity' => '10', 'finish' => 'standard', 'contingency_bp' => 0, 'catalog_lines' => []];
});

it('provides native tabs and redirects the legacy rate screen without loading the browser-admin module', function () {
    foreach (['estimates', 'rates', 'enquiries'] as $tab) {
        $this->get(route('admin.estimations.index', ['tab' => $tab]))->assertOk()->assertSee('Estimation hub tabs')->assertDontSee('/assets/admin-v21/admin-estimator.js');
    }
    $this->get(route('admin.website.estimator'))->assertRedirect(route('admin.estimations.index', ['tab' => 'rates']));
});

it('allows a website-only editor into Rates but not estimates or enquiries', function () {
    $editor = $this->userWithRole('support');
    $editor->syncRoles([]);
    $editor->givePermissionTo('website.edit');
    $this->actingAs($editor)->get(route('admin.estimations.index'))->assertOk()->assertSee('Public estimator service rates');
    $this->get(route('admin.estimations.index', ['tab' => 'estimates']))->assertForbidden();
    $this->get(route('admin.estimations.index', ['tab' => 'enquiries']))->assertForbidden();
    $this->get(route('admin.estimations.create'))->assertForbidden();
});

it('keeps sales staff out of rate publishing even when a hidden action is requested', function () {
    $sales = $this->userWithRole('sales');
    Livewire::actingAs($sales)->test(EstimationsIndex::class)->call('publishRates')->assertForbidden();
    Livewire::actingAs($sales)->test(EstimationsIndex::class)->set('tab', 'rates')->assertForbidden();
});

it('paginates old work and filters by service status and search', function () {
    for ($i = 0; $i < 21; $i++) {
        app(SaveEstimate::class)->handle([...$this->input, 'title' => 'Hub row '.$i], $this->owner);
    }
    $view = Livewire::test(EstimationsIndex::class);
    $view->assertSee('Hub row 20')->assertDontSee('Hub row 0</p>', false)->call('setPage', 2, 'estimatesPage')->assertSee('Hub row 0');
    $view->set('search', 'Hub row 20')->assertSee('Hub row 20')->assertDontSee('Hub row 19');
    $view->set('status', 'converted')->assertDontSee('Hub row 20');
    $view->set('status', '')->set('service', 'furniture')->assertDontSee('Hub row 20');
});

it('scopes estimates and enquiry actions to visible leads rather than exposing another salesperson records', function () {
    $sales = $this->userWithRole('sales');
    $foreign = Lead::factory()->create(['owner_id' => $this->owner->id, 'source' => 'estimator', 'service' => 'interior-design', 'area_sqft' => 10]);
    $e = app(SaveEstimate::class)->handle([...$this->input, 'contact_id' => $foreign->contact_id, 'lead_id' => $foreign->id, 'title' => 'Private estimate marker'], $this->owner);
    Livewire::actingAs($sales)->test(EstimationsIndex::class)->assertDontSee('Private estimate marker');
    Livewire::actingAs($sales)->test(EstimationsIndex::class, ['tab' => 'enquiries'])->assertDontSee($foreign->ref)->call('openEnquiry', $foreign->id)->assertForbidden();
    Livewire::actingAs($sales)->test(EstimateBuilder::class, ['estimate' => $e->id])->assertForbidden();
});

it('opens the same existing enquiry estimate on repeated hub requests', function () {
    $lead = Lead::factory()->create(['owner_id' => $this->owner->id, 'source' => 'estimator', 'service' => 'interior-design', 'area_sqft' => 10]);
    Livewire::test(EstimationsIndex::class, ['tab' => 'enquiries'])->call('openEnquiry', $lead->id)->assertRedirect();
    $id = Estimate::query()->sole()->id;
    Livewire::test(EstimationsIndex::class, ['tab' => 'enquiries'])->call('openEnquiry', $lead->id)->assertRedirect(route('admin.estimations.show', $id));
    expect(Estimate::count())->toBe(1);
});

it('keeps unchanged reviews but invalidates a deliberately repriced edit', function () {
    $e = app(SaveEstimate::class)->handle($this->input, $this->owner);
    app(ReviewEstimate::class)->handle($e, $this->owner);
    app(SaveEstimate::class)->handle($this->input, $this->owner, $e);
    expect($e->refresh()->status)->toBe(EstimateStatus::Reviewed);
    $view = Livewire::test(EstimateBuilder::class, ['estimate' => $e->id])->set('quantity', '20')->call('save')->assertHasErrors('confirmReprice');
    expect($e->refresh()->inputs['quantity'])->toBe('10');
    $view->set('confirmReprice', true)->call('save')->assertHasNoErrors();
    expect($e->refresh()->status)->toBe(EstimateStatus::Draft)->and($e->reviewed_at)->toBeNull()->and($e->reviewed_by)->toBeNull();
});

it('refuses dirty conversion and concurrent-session edits instead of silently changing the snapshot', function () {
    $e = app(SaveEstimate::class)->handle($this->input, $this->owner);
    Livewire::test(EstimateBuilder::class, ['estimate' => $e->id])->set('title', 'Unsaved title')->call('convert')->assertHasErrors('estimate');
    expect($e->refresh()->quote_id)->toBeNull();
    $view = Livewire::test(EstimateBuilder::class, ['estimate' => $e->id]);
    app(SaveEstimate::class)->handle([...$this->input, 'title' => 'Other session'], $this->owner, $e);
    $view->call('review')->assertHasErrors('estimate');
});

it('keeps retired-service saved history visible convertible and immutable after conversion', function () {
    $e = app(SaveEstimate::class)->handle($this->input, $this->owner);
    EstimatorRate::where('key', 'interior-design')->update(['is_active' => false]);
    Livewire::test(EstimateBuilder::class, ['estimate' => $e->id])->assertSee('Saved snapshot')->call('convert')->assertRedirect();
    expect($e->refresh()->status)->toBe(EstimateStatus::Converted);
    Livewire::test(EstimateBuilder::class, ['estimate' => $e->id])->call('save')->assertForbidden();
});

it('publishes through the native Rates screen without changing historical estimates or the public catalogue', function () {
    $e = app(SaveEstimate::class)->handle($this->input, $this->owner);
    $snapshot = $e->computed;
    $catalogue = RateBook::read()['catalog'];
    Livewire::test(EstimationsIndex::class, ['tab' => 'rates'])->set('book.services.0.rates.standard', 275)->call('publishRates')->assertHasNoErrors()->assertSee('Published.');
    expect(EstimatorRate::where('key', 'interior-design')->value('standard_minor'))->toBe(27500)
        ->and(RateBook::read()['catalog'])->toBe($catalogue)->and(\App\Support\JsonObjects::canonical($e->refresh()->computed))->toBe(\App\Support\JsonObjects::canonical($snapshot));
});

it('rejects stale rate editors and compensates exact public bytes when database writing fails', function () {
    $read = $this->books->read($this->owner);
    $input = $read['book'];
    $input['services'][0]['rates']['standard'] = 275;
    $this->books->publish($this->owner, $input, $read['revision']);
    expect(fn () => $this->books->publish($this->owner, $read['book'], $read['revision']))->toThrow(RuntimeException::class, 'Rates changed');
    $before = file_get_contents(RateBook::path());
    EstimatorRate::saving(function () {
        throw new RuntimeException('Simulated DB write failure');
    });
    $input['services'][0]['rates']['standard'] = 280;
    expect(fn () => $this->books->publish($this->owner, $input, $this->books->revision()))->toThrow(RuntimeException::class, 'Simulated DB');
    expect(file_get_contents(RateBook::path()))->toBe($before)->and(EstimatorRate::where('key', 'interior-design')->value('standard_minor'))->toBe(27500);
});

it('does not mutate rates or create named-book settings while observing drift', function () {
    EstimatorRate::where('key', 'interior-design')->update(['standard_minor' => 99900]);
    $before = file_get_contents(RateBook::path());
    $settings = DB::table('settings')->count();
    $read = $this->books->read($this->owner);
    expect($read['consistent'])->toBeFalse()->and(DB::table('settings')->count())->toBe($settings)
        ->and(file_get_contents(RateBook::path()))->toBe($before)->and(EstimatorRate::where('key', 'interior-design')->value('standard_minor'))->toBe(99900);
});

it('loads named books as draft and deactivates removed services without deleting their historical rows', function () {
    $read = $this->books->read($this->owner);
    $this->books->template($this->owner, ['op' => 'save', 'name' => 'Before change']);
    $input = $read['book'];
    $input['services'] = array_values(array_filter($input['services'], fn ($s) => $s['key'] !== 'furniture'));
    $this->books->publish($this->owner, $input, $read['revision']);
    $revision = $this->books->revision();
    $loaded = $this->books->template($this->owner, ['op' => 'apply', 'name' => 'Before change']);
    expect($loaded['book']['services'])->toHaveCount(6)->and($this->books->revision())->toBe($revision)
        ->and(EstimatorRate::where('key', 'furniture')->sole()->is_active)->toBeFalse();
});

it('rejects malformed quantities and incomplete add-ons with visible field errors', function () {
    Livewire::test(EstimateBuilder::class)->set('title', 'Validation fixture')->set('serviceKey', 'interior-design')->set('quantity', '-20')->call('save')->assertHasErrors('quantity');
    Livewire::test(EstimateBuilder::class)->set('title', 'Validation fixture')->set('serviceKey', 'interior-design')->set('quantity', '20')->call('addLine')->call('save')->assertHasErrors('catalogLines.0.catalog_item_id');
});

it('matches the public rate book base headline and range for every service and finish', function () {
    $book = RateBook::read();
    $this->books->publish($this->owner, $book, $this->books->revision());
    foreach ($book['services'] as $s) {
        $rate = EstimatorRate::where('key', $s['key'])->sole();
        foreach (RateBook::FINISHES as $finish) {
            $c = app(ComputeEstimate::class)->handle($rate, '10', $finish, [], 0);
            expect($c['headline_minor'])->toBe($s['rates'][$finish] * 100 * 10)
                ->and($c['range_low_minor'])->toBe($s['rates']['essential'] * 100 * 10)
                ->and($c['range_high_minor'])->toBe($s['rates']['premium'] * 100 * 10);
        }
    }
});

it('retains the Furniture brand on linked enquiries for newly added service keys', function () {
    EstimatorRate::create(['key' => 'wardrobes', 'label' => 'Wardrobes', 'unit' => 'sft', 'unit_label' => 'Area', 'essential_minor' => 10000, 'standard_minor' => 20000, 'premium_minor' => 30000, 'is_active' => true, 'position' => 10]);
    $lead = Lead::factory()->create(['owner_id' => $this->owner->id, 'business_line' => 'furniture', 'source' => 'estimator', 'service' => 'wardrobes', 'area_sqft' => 10]);
    $quote = app(CreateQuoteFromEstimate::class)->handle($lead, $this->owner);
    expect($quote->business_line->value)->toBe('furniture')->and($quote->items()->sole()->product_name)->toBe('Wardrobes');
});
