<?php

use App\Domain\Commerce\Models\Category;
use App\Domain\Commerce\Services\ProductCatalogue;
use App\Domain\Commerce\Services\ProductMerchandising;
use App\Domain\Commerce\Services\ProductTransfer;
use App\Livewire\Admin\Commerce\Products;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->editor = $this->userWithRole('admin');
    $this->actingAs($this->editor);
    $this->service = app(ProductCatalogue::class);
    $category = Category::where('slug', 'chairs')->sole();
    $this->draft = array_replace($this->service->defaults(), ['name' => 'Reviewed public chair', 'slug' => 'workflow-chair', 'price' => '1200.00', 'category_ids' => [$category->id], 'primary_category_id' => $category->id, 'description' => 'Keep the real description', 'reviewed' => true]);
});

it('quick edits preserve full content and publication while resetting review and enforcing optimistic locking', function () {
    $product = $this->service->save(null, $this->draft, 0, $this->editor);
    $snapshot = DB::table('commerce_product_revisions')->where('product_id', $product->id)->value('id');
    $product->update(['published_revision_id' => $snapshot]);
    $first = Livewire::test(Products::class)->call('openQuickEdit', $product->id);
    $stale = Livewire::test(Products::class)->call('openQuickEdit', $product->id);
    $first->set('quick.name', 'New draft chair')->set('quick.is_hot', true)->set('quick.is_featured', true)->set('quick.sale_price', '1000.00')->call('saveQuickEdit')->assertHasNoErrors()->assertSet('showQuickEdit', false);
    expect($product->fresh()->draft['description'])->toBe('Keep the real description')->and($product->fresh()->draft['reviewed'])->toBeFalse()->and($product->fresh()->published_revision_id)->toBe($snapshot)->and($product->fresh()->draft['is_hot'])->toBeTrue();
    $stale->set('quick.price', '999.00')->call('saveQuickEdit')->assertHasErrors('conflict');
});

it('searches and filters published content for viewers without matching newer draft identities', function () {
    $product = $this->service->save(null, $this->draft, 0, $this->editor);
    $snapshot = DB::table('commerce_product_revisions')->where('product_id', $product->id)->value('id');
    $product->update(['published_revision_id' => $snapshot]);
    $changed = array_replace($product->draft, ['name' => 'Confidential draft identity', 'category_ids' => [], 'primary_category_id' => null]);
    $this->service->save($product, $changed, $product->version, $this->editor);
    $this->actingAs($this->userWithRole('sales'));
    Livewire::test(Products::class)->set('search', 'Confidential')->assertViewHas('products', fn ($rows) => $rows->total() === 0);
    Livewire::test(Products::class)->set('search', 'Reviewed public')->set('categoryFilter', (string) $this->draft['primary_category_id'])->assertSee('Reviewed public chair')->assertDontSee('Confidential draft identity');
    Livewire::test(Products::class)->call('openQuickEdit', $product->id)->assertForbidden();
});

it('clears hidden bulk selections when filters change and shows full hierarchy paths', function () {
    $product = $this->service->save(null, $this->draft, 0, $this->editor);
    Livewire::test(Products::class)->set('selected', [$product->id])->set('confirmBulk', true)->set('categoryFilter', (string) $this->draft['primary_category_id'])->assertSet('selected', [])->assertSet('confirmBulk', false)->assertSee('Office');
});

it('validates integer-cent discounts schedules and explicit independent variant sales', function () {
    $sale = array_replace($this->draft, ['sale_price' => '999.99', 'sale_start' => '2026-11-12T10:00', 'sale_end' => '2026-11-12T12:00', 'variants' => [['sku' => 'A', 'price' => '2000.00', 'sale_price' => '1800.00', 'availability' => 'on_request']]]);
    $valid = $this->service->validate($sale);
    expect($valid['variants'][0]['sale_price'])->toBe('1800.00');
    $pricing = app(ProductMerchandising::class);
    expect($pricing->effectivePrice($valid, CarbonImmutable::parse('2026-11-12 09:59:59', 'UTC')))->toBe('1200.00')
        ->and($pricing->effectivePrice($valid, CarbonImmutable::parse('2026-11-12 10:00:00', 'UTC')))->toBe('999.99')
        ->and($pricing->effectivePrice($valid, CarbonImmutable::parse('2026-11-12 12:00:00', 'UTC')))->toBe('1200.00')
        ->and($pricing->effectivePrice(['price' => '2000.00']))->toBe('2000.00');
    expect(fn () => $this->service->validate(array_replace($sale, ['sale_price' => '1200.00'])))->toThrow(ValidationException::class);
    expect(fn () => $this->service->validate(array_replace($sale, ['sale_end' => '2026-11-12T09:00'])))->toThrow(ValidationException::class);
    expect(fn () => $this->service->validate(array_replace($sale, ['sale_price' => ''])))->toThrow(ValidationException::class);
});

it('retains merchandising in portable exports without auto publishing and rejects invalid primary selections', function () {
    $data = array_replace($this->draft, ['sale_price' => '0', 'is_hot' => true, 'is_featured' => true, 'featured_position' => 4]);
    $product = $this->service->save(null, $data, 0, $this->editor);
    $package = app(ProductTransfer::class)->export([$product->id]);
    expect($package['items'][0]['draft']['sale_price'])->toBe('0')->and($package['items'][0]['draft']['is_hot'])->toBeTrue()
        ->and($package['items'][0]['draft']['featured_position'])->toBe(4)->and($product->fresh()->published_revision_id)->toBeNull();
    Livewire::test(Products::class)->call('openQuickEdit', $product->id)->set('quick.category_ids', [])->call('saveQuickEdit')->assertHasErrors('primary_category_id')->assertSet('showQuickEdit', true);
    expect(app(ProductMerchandising::class)->effectivePrice($product->draft))->toBe('0');
});
