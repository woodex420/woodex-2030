<?php

use App\Domain\Commerce\Models\Category;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\ProductMedia;
use App\Domain\Commerce\Services\ProductCatalogue;
use App\Domain\Commerce\Services\ProductTransfer;
use App\Livewire\Admin\Commerce\ProductEditor;
use App\Livewire\Admin\Commerce\Products;
use App\Livewire\Admin\Commerce\ProductTransfers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->editor = $this->userWithRole('admin');
    $this->actingAs($this->editor);
    Storage::fake('local');
});

function nativeDraft(array $overrides = []): array
{
    $category = Category::where('slug', 'executive-tables')->sole();

    return array_replace(app(ProductCatalogue::class)->defaults(), ['name' => 'SYNTHETIC Desk', 'slug' => 'synthetic-desk', 'price' => '1200.50', 'category_ids' => [$category->id], 'primary_category_id' => $category->id], $overrides);
}

it('creates complete drafts with multi category structured variants and rejects stale editors', function () {
    $service = app(ProductCatalogue::class);
    $data = nativeDraft(['category_ids' => Category::whereIn('slug', ['executive-tables', 'manager-tables'])->pluck('id')->all(), 'variants' => [['sku' => 'TEST-1', 'colour' => 'Walnut', 'material' => 'Reviewed material', 'size' => '1200 mm', 'price' => '1350.75', 'availability' => 'unavailable']]]);
    $product = $service->save(null, $data, 0, $this->editor);
    $first = Livewire::test(ProductEditor::class, ['product' => $product]);
    $stale = Livewire::test(ProductEditor::class, ['product' => $product]);
    $first->set('form.name', 'Updated draft name')->call('saveDraft')->assertHasNoErrors();
    $stale->set('form.name', 'Stale name')->call('saveDraft')->assertHasErrors('conflict');
    expect($product->fresh()->draft['variants'][0]['price'])->toBe('1350.75')->and($product->fresh()->draft['category_ids'])->toHaveCount(2)
        ->and($product->fresh()->published_revision_id)->toBeNull()->and(DB::table('commerce_product_revisions')->where('product_id', $product->id)->count())->toBe(2);
});

it('uploads actual image files reorders gallery retains historical media and rejects SVG or cross-product IDs', function () {
    $editor = Livewire::test(ProductEditor::class)->set('form', nativeDraft())->call('saveDraft')->assertHasNoErrors();
    $editor->set('uploads', [UploadedFile::fake()->image('desk.png'), UploadedFile::fake()->image('chair.jpg')])->call('uploadImages')->assertHasNoErrors();
    $product = Product::sole();
    $images = $product->draft['images'];
    expect($images)->toHaveCount(2);
    $editor->call('moveRow', 'images', 1, -1)->set('form.images.0.alt', 'Actual uploaded test fixture')->call('saveDraft')->assertHasNoErrors();
    expect($product->fresh()->draft['images'][0]['id'])->toBe($images[1]['id']);
    $this->get(route('admin.commerce.product-media', $images[0]['id']))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $editor->call('removeRow', 'images', 0)->call('saveDraft')->assertHasNoErrors();
    Storage::disk('local')->assertExists(ProductMedia::find($images[1]['id'])->path);
    $editor->set('uploads', [UploadedFile::fake()->createWithContent('unsafe.svg', '<svg><script>alert(1)</script></svg>')])->call('uploadImages')->assertHasErrors();
    $other = app(ProductCatalogue::class)->save(null, nativeDraft(['slug' => 'other-desk']), 0, $this->editor);
    Livewire::test(ProductEditor::class, ['product' => $other])->set('form.images', [['id' => $images[0]['id'], 'alt' => 'Forged']])->call('saveDraft')->assertHasErrors('images.0.id');
});

it('requires reviewed complete content exact per-user preview and explicit publication while retaining history', function () {
    $editor = Livewire::test(ProductEditor::class)->set('form', nativeDraft())->call('saveDraft');
    $product = Product::sole();
    $editor->set('confirmPublish', true)->call('publish')->assertHasErrors('publish');
    $editor->set('form.description', '**Reviewed product description**')->set('form.shipping', 'Reviewed delivery terms')->set('form.warranty', 'Reviewed warranty terms')
        ->set('form.reviewed', true)->set('uploads', [UploadedFile::fake()->image('fixture.png')])->call('uploadImages')->assertHasNoErrors()
        ->set('form.images.0.alt', 'Verified test fixture')->call('saveDraft')->assertHasNoErrors();
    $this->get(route('admin.commerce.products.preview', $product))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertSee('<strong>Reviewed product description</strong>', false);
    $editor->set('confirmPublish', true)->call('publish')->assertHasNoErrors();
    $published = $product->fresh()->published_revision_id;
    expect($published)->not->toBeNull();
    $editor->set('form.name', 'New private draft')->call('saveDraft');
    expect($product->fresh()->published_revision_id)->toBe($published);
    $this->actingAs($this->userWithRole('sales'));
    $this->get(route('admin.commerce.products.preview', $product))->assertOk()->assertDontSee('New private draft');
    $this->actingAs($this->editor);
    app(ProductCatalogue::class)->withdraw($product, $this->editor);
    expect($product->fresh()->published_revision_id)->toBeNull()->and(DB::table('commerce_product_revisions')->where('product_id', $product->id)->count())->toBeGreaterThan(1);
});

it('does not allow view-only users to mutate transfer or read unpublished media', function () {
    $product = app(ProductCatalogue::class)->save(null, nativeDraft(), 0, $this->editor);
    $fixture = UploadedFile::fake()->image('private.png');
    $media = app(ProductTransfer::class)->storeImage($product, file_get_contents($fixture->getRealPath()));
    $this->actingAs($this->userWithRole('sales'));
    $this->get(route('admin.commerce.products'))->assertOk();
    $this->get(route('admin.commerce.products.edit', $product))->assertForbidden();
    $this->get(route('admin.commerce.products.transfers'))->assertForbidden();
    $this->get(route('admin.commerce.products.preview', $product))->assertForbidden();
    $this->get(route('admin.commerce.product-media', $media))->assertForbidden();
    Livewire::test(Products::class)->set('selected', [$product->id])->call('export')->assertForbidden();
    Livewire::test(Products::class)->set('selected', [$product->id])->set('confirmBulk', true)->call('bulk', 'archive')->assertForbidden();
});

it('round trips a portable package including real media while skipping existing records and not trusting publication state', function () {
    $service = app(ProductCatalogue::class);
    $transfer = app(ProductTransfer::class);
    $product = $service->save(null, nativeDraft(['description' => 'Original description', 'reviewed' => true]), 0, $this->editor);
    $fixture = UploadedFile::fake()->image('portable.png');
    $media = $transfer->storeImage($product, file_get_contents($fixture->getRealPath()));
    $data = $product->draft;
    $data['images'] = [['id' => $media->id, 'alt' => 'Portable image']];
    $product = $service->save($product, $data, $product->version, $this->editor);
    $package = $transfer->export([$product->id]);
    expect($package['assets'])->toHaveCount(1)->and($package['items'][0]['draft'])->not->toHaveKey('category_ids');
    expect($transfer->importPackage(json_encode($package), $this->editor))->toBe(0);
    $package['items'][0]['draft']['slug'] = 'portable-copy';
    expect($transfer->importPackage(json_encode($package), $this->editor))->toBe(1);
    $copy = Product::where('slug', 'portable-copy')->sole();
    expect($copy->draft['reviewed'])->toBeFalse()->and($copy->published_revision_id)->toBeNull()->and($copy->draft['images'][0]['alt'])->toBe('Portable image')
        ->and($copy->draft['category_ids'])->toBe($product->draft['category_ids']);
    Storage::disk('local')->assertExists(ProductMedia::find($copy->draft['images'][0]['id'])->path);
});

it('rejects tampered import media and protects bulk actions with confirmation', function () {
    $product = app(ProductCatalogue::class)->save(null, nativeDraft(), 0, $this->editor);
    $package = app(ProductTransfer::class)->export([$product->id]);
    $package['assets'][str_repeat('a', 64)] = base64_encode('<script>unsafe</script>');
    expect(fn () => app(ProductTransfer::class)->parsePackage(json_encode($package)))->toThrow(ValidationException::class);
    Livewire::test(Products::class)->set('selected', [$product->id])->call('bulk', 'archive')->assertHasErrors('bulk');
    Livewire::test(Products::class)->set('selected', [$product->id])->set('confirmBulk', true)->call('bulk', 'archive')->assertHasNoErrors();
    expect($product->fresh()->archived_at)->not->toBeNull();
    Livewire::test(Products::class)->set('selected', [$product->id])->set('confirmBulk', true)->call('bulk', 'restore')->assertHasNoErrors();
    expect($product->fresh()->archived_at)->toBeNull()->and($product->fresh()->published_revision_id)->toBeNull();
});

it('shows explicit Soon shop and Series pages and the source import audit', function () {
    $this->get(route('admin.commerce.overview'))->assertOk()->assertSee('Commerce Overview');
    $this->get(route('admin.commerce.shop'))->assertOk()->assertSee('Shop customization')->assertSee('Soon');
    $this->get(route('admin.commerce.series'))->assertOk()->assertSee('Soon')->assertSee('Open existing Series organizer');
    $this->get(route('admin.commerce.products.transfers'))->assertOk()->assertSee('148 source products')->assertSee('18 distinct source photographs');
    Livewire::test(ProductTransfers::class)->call('importSource')->assertHasErrors('import');
    expect(Product::count())->toBe(0);
});

it('imports all source products as reviewable drafts idempotently without inventing variants or stock', function () {
    Storage::fake('source-images');
    $source = app(ProductTransfer::class)->source();
    $fixture = UploadedFile::fake()->image('source.jpg');
    foreach (array_unique(array_merge(...array_column($source['items'], 'images'))) as $path) {
        Storage::disk('source-images')->put(basename($path), file_get_contents($fixture->getRealPath()));
    }
    config(['woodex.catalogue_source_image_root' => Storage::disk('source-images')->path('')]);
    expect(app(ProductTransfer::class)->importSource($this->editor))->toBe(148);
    expect(Product::count())->toBe(148)->and(Product::whereNotNull('published_revision_id')->count())->toBe(0);
    $first = Product::where('source_key', 'ballmer-executive-desk')->sole();
    expect($first->draft['variants'])->toBe([])->and($first->draft['availability'])->toBe('on_request')->and($first->draft['reviewed'])->toBeFalse()
        ->and($first->draft['images'])->toHaveCount(1)->and($first->review_issues)->not->toBeEmpty()->and($first->source_record['colors'])->toHaveCount(3);
    expect(app(ProductTransfer::class)->importSource($this->editor))->toBe(0);
    $this->get(route('admin.commerce.products.edit', $first))->assertOk()->assertSee('Source review required')->assertSee('Source colour labels');
});

it('applies bulk classification only to drafts and forces a fresh review', function () {
    $product = app(ProductCatalogue::class)->save(null, nativeDraft(['reviewed' => true]), 0, $this->editor);
    $category = Category::where('slug', 'chairs')->sole();
    Livewire::test(Products::class)->set('selected', [$product->id])->set('bulkCategory', (string) $category->id)->set('bulkAvailability', 'unavailable')
        ->set('confirmBulk', true)->call('applyDraftChanges')->assertHasNoErrors();
    expect($product->fresh()->draft['category_ids'])->toContain($category->id)->and($product->fresh()->draft['reviewed'])->toBeFalse()
        ->and($product->fresh()->draft['availability'])->toBe('unavailable')->and($product->fresh()->published_revision_id)->toBeNull();
});

it('keeps source-key imports and image paths from bypassing the reviewed draft boundary', function () {
    $service = app(ProductCatalogue::class);
    $product = $service->save(null, nativeDraft(), 0, $this->editor);
    Livewire::test(ProductEditor::class, ['product' => $product])->set('form.variants', [
        ['sku' => 'SAME', 'colour' => 'A', 'material' => 'M', 'size' => 'S', 'price' => '1.00', 'availability' => 'available'],
        ['sku' => 'SAME', 'colour' => 'B', 'material' => 'M', 'size' => 'S', 'price' => '2.00', 'availability' => 'available'],
    ])->call('saveDraft')->assertHasErrors();
    $this->actingAs($this->userWithRole('sales'));
    $this->get(route('admin.commerce.products'))->assertOk()->assertDontSee('SYNTHETIC Desk');
    $manager = $this->userWithRole('sales_manager');
    $this->actingAs($manager);
    Livewire::test(ProductEditor::class, ['product' => $product])->set('confirmPublish', true)->call('publish')->assertForbidden();
});
