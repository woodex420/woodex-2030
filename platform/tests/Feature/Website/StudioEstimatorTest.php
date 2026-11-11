<?php

declare(strict_types=1);

use App\Domain\Sales\Models\EstimatorRate;
use App\Domain\Website\Studio\RateBook;
use App\Support\AdminNavigation;
use Database\Seeders\RateBookSeeder;
use Illuminate\Support\Facades\File;

/*
| The Estimator screen, ported from the v2.1 admin (docs/audit/16.14).
|
| The public estimator is a v1 page: it reads `assets/js/estimator-rates.js`, not the database. The screen
| therefore edits that file, and one save also mirrors the rates into `estimator_rates` so the bot and the
| staff estimate builder never price from different numbers.
*/

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
    $this->actingAs($this->owner);

    // Never touch the real public file from a test: point the rate book at a copy.
    $dir = storage_path('framework/testing');
    File::ensureDirectoryExists($dir);
    $this->rateBook = $dir.'/estimator-rates.js';
    File::copy(public_path('assets/js/estimator-rates.js'), $this->rateBook);
    config(['website.rate_book_path' => $this->rateBook]);

    $this->seed(RateBookSeeder::class);
});

it('opens the estimator screen inside the OS session', function () {
    $this->get(route('admin.website.estimator'))
        ->assertRedirect(route('admin.estimations.index', ['tab' => 'rates']));
    $this->get(route('admin.estimations.index', ['tab' => 'rates']))->assertOk()->assertSee('Public estimator service rates')->assertDontSee('/assets/admin-v21/admin-estimator.js', false);
});

it('keeps people without website.edit out', function () {
    $this->actingAs($this->userWithRole('sales'));

    $this->get(route('admin.website.estimator'))->assertForbidden();
    $this->postJson(route('admin.website.studio.api'), ['action' => 'cms_estimator'])->assertForbidden();
});

it('serves the book the public page actually reads', function () {
    $r = $this->postJson(route('admin.website.studio.api'), ['action' => 'cms_estimator'])
        ->assertOk()
        ->assertJsonPath('ok', true);

    $services = collect($r->json('book.services'))->keyBy('key');

    expect($services)->toHaveCount(6)
        ->and($services['interior-design']['label'])->toBe('Interior Design')
        ->and($services['interior-design']['rates']['standard'])->toBe(250)
        ->and($services['3d-visualization']['unit'])->toBe('views')
        ->and($r->json('book.finishes'))->toHaveCount(3)
        ->and($r->json('book.finishes.0.label'))->toBe('Essential')
        ->and($r->json('book.terms'))->toHaveCount(3)
        ->and($r->json('book.catalog_count'))->toBe(22)
        ->and($r->json('page'))->toBe('/estimator/');
});

it('saves a rate into the public file and into estimator_rates together', function () {
    $book = $this->postJson(route('admin.website.studio.api'), ['action' => 'cms_estimator'])->json('book');

    foreach ($book['services'] as &$service) {
        if ($service['key'] === 'interior-design') {
            $service['rates']['standard'] = 275;
        }
    }

    $this->postJson(route('admin.website.studio.api'), [
        'action' => 'cms_estimator_save',
        'book' => ['services' => $book['services'], 'finishes' => $book['finishes'], 'terms' => $book['terms']],
    ])->assertOk()->assertJsonPath('ok', true);

    $onDisk = collect(RateBook::read()['services'])->keyBy('key');

    expect($onDisk['interior-design']['rates']['standard'])->toBe(275)
        ->and(EstimatorRate::query()->where('key', 'interior-design')->value('standard_minor'))->toBe(27500)
        // The catalogue the screen does not edit is written back untouched.
        ->and(RateBook::read()['catalog'])->toHaveCount(22);
});

it('refuses rates that do not climb and leaves the book alone', function () {
    $before = RateBook::read();

    $this->postJson(route('admin.website.studio.api'), [
        'action' => 'cms_estimator_save',
        'book' => [
            'services' => [[
                'key' => 'interior-design',
                'label' => 'Interior Design',
                'hint' => 'Design concept',
                'unit' => 'sqft',
                'unitLabel' => 'Area (sq ft)',
                'rates' => ['essential' => 400, 'standard' => 250, 'premium' => 500],
            ]],
            'finishes' => $before['finishes'],
            'terms' => $before['terms'],
        ],
    ])->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error', '“Interior Design”: the rates have to climb — Essential ≤ Standard ≤ Premium.');

    expect(RateBook::read()['services'])->toBe($before['services']);
});

it('adds a service with a key and switches a removed one off instead of deleting it', function () {
    $book = $this->postJson(route('admin.website.studio.api'), ['action' => 'cms_estimator'])->json('book');

    // Remove Custom Furniture, add an unbranded new service: the server has to make a stable key for it.
    $services = array_values(array_filter($book['services'], fn (array $s): bool => $s['key'] !== 'furniture'));
    $services[] = [
        'key' => '',
        'label' => 'False Ceiling',
        'hint' => 'Gypsum ceiling with cove',
        'unit' => 'sqft',
        'unitLabel' => 'Ceiling area (sq ft)',
        'rates' => ['essential' => 180, 'standard' => 280, 'premium' => 450],
    ];

    $this->postJson(route('admin.website.studio.api'), [
        'action' => 'cms_estimator_save',
        'book' => ['services' => $services, 'finishes' => $book['finishes'], 'terms' => $book['terms']],
    ])->assertOk()->assertJsonPath('ok', true);

    $added = EstimatorRate::query()->where('key', 'false-ceiling')->first();
    $removed = EstimatorRate::query()->where('key', 'furniture')->first();

    expect($added)->not->toBeNull()
        ->and($added->essential_minor)->toBe(18000)
        ->and($added->unit)->toBe('sft')
        ->and($removed)->not->toBeNull()
        ->and($removed->is_active)->toBeFalse();
});

it('keeps a named rate book and loads it back without publishing it', function () {
    $this->postJson(route('admin.website.studio.api'), ['action' => 'cms_estimator_tpl', 'op' => 'save', 'name' => '2026 standard'])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('templates.0.name', '2026 standard');

    // Change a price and publish it, then load the snapshot back into the editor.
    $book = $this->postJson(route('admin.website.studio.api'), ['action' => 'cms_estimator'])->json('book');

    foreach ($book['services'] as &$service) {
        if ($service['key'] === 'interior-design') {
            $service['rates']['standard'] = 300;
        }
    }

    $this->postJson(route('admin.website.studio.api'), [
        'action' => 'cms_estimator_save',
        'book' => ['services' => $book['services'], 'finishes' => $book['finishes'], 'terms' => $book['terms']],
    ])->assertOk();

    $loaded = $this->postJson(route('admin.website.studio.api'), [
        'action' => 'cms_estimator_tpl', 'op' => 'apply', 'name' => '2026 standard',
    ])->assertOk()->json('book.services');

    $byKey = collect($loaded)->keyBy('key');

    expect($byKey['interior-design']['rates']['standard'])->toBe(250)
        // Loading is not saving: the published book still has the new price.
        ->and(collect(RateBook::read()['services'])->keyBy('key')['interior-design']['rates']['standard'])->toBe(300);

    $this->postJson(route('admin.website.studio.api'), [
        'action' => 'cms_estimator_tpl', 'op' => 'delete', 'name' => '2026 standard',
    ])->assertOk()->assertJsonPath('templates', []);
});

it('lists Estimator as a live destination in the sidebar', function () {
    $items = collect(AdminNavigation::for($this->owner))
        ->flatMap(fn (array $group): array => $group['items'])
        ->keyBy('label');

    expect($items['Estimations']['route'])->toBe('admin.estimations.index')
        // FAQs and Testimonials are live too; no Website screen is listed as planned any more.
        ->and($items->filter(fn (array $i): bool => ($i['phase'] ?? null) === 'soon'))->toHaveCount(0);
});
