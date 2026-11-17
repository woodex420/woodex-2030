<?php

declare(strict_types=1);

use App\Domain\Commerce\Models\Category;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Services\ProductCatalogue;
use App\Domain\Commerce\Services\WoodexCatalogue;
use App\Livewire\Admin\Commerce\Products;
use App\Livewire\Admin\Commerce\ProductTransfers;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
| The real catalogue: the 170 products of woodexfurniture.pk (captured 16 November 2026), released by the owner
| for the admin on 17 November 2026 (decision log DL-022). It arrives as drafts and overwrites nothing.
| The file is built by scripts/build-woodex-catalogue.mjs; CI fails when it is not what the catalogue gives.
*/

beforeEach(function () {
    $this->editor = $this->userWithRole('admin');
    $this->actingAs($this->editor);
    Storage::fake('local');
});

it('loads every product of the live shop as a draft, with its code, its price and its category', function () {
    $added = app(WoodexCatalogue::class)->import($this->editor);

    expect($added)->toBe(['products' => 170, 'categories' => 8])
        ->and(Product::query()->count())->toBe(170)
        // Nothing is on sale: a draft is seen by staff and by no customer.
        ->and(Product::query()->whereNotNull('published_revision_id')->count())->toBe(0);

    $products = Product::query()->get();

    // Each product carries one code, and no code is carried twice (17 codes were shared by 36 products on the live shop).
    $codes = $products->map(fn (Product $product): string => $product->draft['variants'][0]['sku']);
    expect($codes->unique()->count())->toBe(170)
        ->and($products->every(fn (Product $product): bool => count($product->draft['variants']) === 1))->toBeTrue()
        // No stock figure is in the capture, and none is made up.
        ->and($products->every(fn (Product $product): bool => $product->draft['availability'] === 'on_request'))->toBeTrue()
        // A product the live shop sells at PKR 0 has no price: 33 of them.
        ->and($products->filter(fn (Product $product): bool => $product->draft['price'] === '')->count())->toBe(33)
        // A reduced price keeps the price it was reduced from: 10 of them.
        ->and($products->filter(fn (Product $product): bool => $product->draft['sale_price'] !== '')->count())->toBe(10)
        // The points of the import report travel with the product: 116 have at least one.
        ->and($products->filter(fn (Product $product): bool => ! empty($product->review_issues))->count())->toBe(116)
        ->and($products->every(fn (Product $product): bool => $product->draft['category_ids'] !== [] && $product->draft['reviewed'] === false))->toBeTrue();

    $table = Product::query()->where('slug', 'ambassador-executive-table')->sole();
    expect($table->name)->toBe('Ambassador Executive Table')
        ->and($table->draft['variants'][0]['sku'])->toBe('WST-10001')
        // The live shop: PKR 15,000, reduced from PKR 185,000.
        ->and($table->draft['price'])->toBe('185000')
        ->and($table->draft['sale_price'])->toBe('15000')
        ->and($table->source_key)->toBe('ambassador-executive-table')
        // What the live shop showed is kept whole, the address of the photograph included.
        ->and($table->source_record['source']['site'])->toBe('woodexfurniture.pk')
        ->and($table->source_record['images'][0])->toStartWith('https://woodexfurniture.pk/')
        ->and($table->review_issues[0])->toContain('92% reduction')
        // Filed under its category and its sub-category; the sub-category is the main one.
        ->and(Category::query()->whereIn('id', $table->draft['category_ids'])->pluck('slug')->all())->toEqualCanonicalizing(['office-tables', 'ceo-tables'])
        ->and(Category::query()->findOrFail($table->draft['primary_category_id'])->slug)->toBe('ceo-tables');

    $onRequest = Product::query()->where('slug', 'aspire-conference-table')->sole();
    expect($onRequest->draft['price'])->toBe('')->and($onRequest->draft['sale_price'])->toBe('');
});

it('adds the categories the live shop sells under, switched off, and changes none that is there', function () {
    $before = Category::query()->orderBy('id')->get(['id', 'name', 'slug', 'parent_id', 'is_active', 'position'])->toArray();

    app(WoodexCatalogue::class)->import($this->editor);

    $added = Category::query()->whereIn('slug', ['ceo-tables', 'conference-tables', 'discussion-tables', 'center-tables', 'executive-chairs', 'manager-chairs', 'staff-chairs', 'visitor-chairs'])->get();
    expect($added)->toHaveCount(8)
        // The owner switches a category on; the import does not put anything in front of a customer.
        ->and($added->every(fn (Category $category): bool => $category->is_active === false))->toBeTrue()
        ->and($added->firstWhere('slug', 'ceo-tables')->name)->toBe('CEO Tables')
        ->and(Category::query()->findOrFail($added->firstWhere('slug', 'ceo-tables')->parent_id)->slug)->toBe('office-tables')
        ->and(Category::query()->findOrFail($added->firstWhere('slug', 'visitor-chairs')->parent_id)->slug)->toBe('chairs')
        ->and(Category::query()->whereIn('id', array_column($before, 'id'))->orderBy('id')->get(['id', 'name', 'slug', 'parent_id', 'is_active', 'position'])->toArray())->toBe($before);
});

it('overwrites nothing and can be run again', function () {
    // A product already entered by hand under an address of the catalogue is left as it is.
    $category = Category::query()->where('slug', 'executive-tables')->sole();
    $own = app(ProductCatalogue::class)->save(null, array_replace(app(ProductCatalogue::class)->defaults(), [
        'name' => 'Our own Ambassador', 'slug' => 'ambassador-executive-table', 'price' => '99000', 'category_ids' => [$category->id], 'primary_category_id' => $category->id,
    ]), 0, $this->editor);

    expect(app(WoodexCatalogue::class)->import($this->editor))->toBe(['products' => 169, 'categories' => 8])
        ->and($own->fresh()->name)->toBe('Our own Ambassador')
        ->and($own->fresh()->draft['price'])->toBe('99000');

    // An imported product that staff have corrected keeps the correction on the next run.
    $edited = Product::query()->where('slug', 'aspire-conference-table')->sole();
    app(ProductCatalogue::class)->save($edited, array_replace($edited->draft, ['price' => '250000']), $edited->version, $this->editor);

    expect(app(WoodexCatalogue::class)->import($this->editor))->toBe(['products' => 0, 'categories' => 0])
        ->and(Product::query()->count())->toBe(170)
        ->and($edited->fresh()->draft['price'])->toBe('250000');
});

it('is offered on the import screen to those who manage the catalogue, behind a tick', function () {
    $this->get(route('admin.commerce.products.transfers'))->assertOk()
        ->assertSee('The Woodex catalogue')
        ->assertSee('170 products · 170 not yet in the admin · 33 priced on request · 116 with a point to check.')
        ->assertSee('CEO Tables');

    Livewire::test(ProductTransfers::class)->call('importCatalogue')->assertHasErrors('catalogue');
    expect(Product::query()->count())->toBe(0);

    Livewire::test(ProductTransfers::class)->set('confirmCatalogue', true)->call('importCatalogue')->assertHasNoErrors();
    expect(Product::query()->count())->toBe(170);

    $this->get(route('admin.commerce.products.transfers'))->assertOk()
        ->assertSee('170 products · 0 not yet in the admin')
        ->assertSee('Every product of the catalogue is in the admin.')
        ->assertDontSee('Load the Woodex catalogue as drafts');

    // A salesperson does not manage the catalogue.
    $sales = $this->userWithRole('sales');
    $this->actingAs($sales)->get(route('admin.commerce.products.transfers'))->assertForbidden();
    expect(fn () => app(WoodexCatalogue::class)->import($sales))->toThrow(HttpException::class);
});

it('is found in the product list by name or by code', function () {
    app(WoodexCatalogue::class)->import($this->editor);

    Livewire::test(Products::class)->set('search', 'wst-10001')
        ->assertSee('Ambassador Executive Table')->assertSee('WST-10001')->assertDontSee('Aspire Conference Table')
        // The photograph has not been copied in: the card links to it on the live shop and loads nothing from there.
        ->assertSee('See it on the live shop')
        ->assertSeeHtml('href="https://woodexfurniture.pk/assets/images/products/')
        ->assertDontSeeHtml('src="https://woodexfurniture.pk');
    Livewire::test(Products::class)->set('search', 'Aspire')
        ->assertSee('Aspire Conference Table')->assertDontSee('Ambassador Executive Table');
});

it('can be loaded from the command line, under the name of a member of staff who manages the catalogue', function () {
    expect(Artisan::call('commerce:import-catalogue'))->toBe(1)
        ->and(Artisan::call('commerce:import-catalogue', ['--as' => 'nobody@example.test']))->toBe(1)
        ->and(Artisan::call('commerce:import-catalogue', ['--as' => $this->userWithRole('sales')->email]))->toBe(1)
        ->and(Product::query()->count())->toBe(0);

    expect(Artisan::call('commerce:import-catalogue', ['--as' => $this->editor->email]))->toBe(0)
        ->and(Artisan::output())->toContain('170 added as drafts')
        ->and(Product::query()->count())->toBe(170);

    expect(Artisan::call('commerce:import-catalogue', ['--as' => $this->editor->email]))->toBe(0)
        ->and(Artisan::output())->toContain('0 added as drafts, 170 already there');
});

it('says in a preview what is real and what is a sample', function () {
    config(['preview.synthetic' => true]);
    if (config('database.default') !== 'sqlite') {
        $this->markTestSkipped('A preview runs on SQLite only.');
    }

    $this->get(route('admin.dashboard'))->assertOk()
        ->assertSee('Demo workspace — sample data.')
        ->assertSee('Everything here is fictional');
    expect(WoodexCatalogue::loaded())->toBeFalse();

    app(WoodexCatalogue::class)->import($this->editor);

    // With the real catalogue in it, the notice no longer calls everything fictional — and still says that
    // nothing typed is kept, on every screen.
    expect(WoodexCatalogue::loaded())->toBeTrue();
    $this->get(route('admin.commerce.products'))->assertOk()
        ->assertSee('data-demo-notice', false)
        ->assertSee('Preview workspace — real catalogue, sample records.')
        ->assertSee('Nothing typed here is kept')
        ->assertSee('Do not enter real customer details or passwords.')
        ->assertDontSee('Everything here is fictional');
});
