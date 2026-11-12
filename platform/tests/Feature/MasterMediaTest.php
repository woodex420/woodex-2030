<?php

use App\Domain\Commerce\Models\ProductMedia;
use App\Domain\Commerce\Services\ProductCatalogue;
use App\Domain\Commerce\Services\ProductTransfer;
use App\Domain\Media\Services\MasterMedia;
use App\Domain\Website\Models\Media;
use App\Livewire\Admin\Website\MediaLibrary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
});

it('retains originals idempotently and keeps previous versions when website bytes change', function () {
    $file = UploadedFile::fake()->image('one.png', 10, 10);
    $first = file_get_contents($file->getRealPath());
    Storage::disk('public')->put('website/test.png', $first);
    $media = Media::create(['disk' => 'public', 'path' => 'website/test.png', 'folder' => 'website/products']);
    $master = app(MasterMedia::class);
    $version = $master->register($media);
    expect($master->register($media))->toBe($version)->and(DB::table('master_media_assets')->count())->toBe(1);
    $second = UploadedFile::fake()->image('two.png', 20, 20);
    Storage::disk('public')->put('website/test.png', file_get_contents($second->getRealPath()));
    $next = $master->register($media);
    expect($next)->not->toBe($version)->and(DB::table('master_media_versions')->count())->toBe(2);
    expect(Storage::disk('local')->get(DB::table('master_media_versions')->find($version)->path))->toBe($first);
});

it('pins product references to shared master versions without rewriting historical usage', function () {
    $owner = $this->userWithRole('owner');
    $catalogue = app(ProductCatalogue::class);
    $draft = array_replace($catalogue->defaults(), ['name' => 'Master test', 'slug' => 'master-test']);
    $product = $catalogue->save(null, $draft, 0, $owner);
    $file = UploadedFile::fake()->image('shared.png');
    $bytes = file_get_contents($file->getRealPath());
    Storage::disk('public')->put('website/shared.png', $bytes);
    $website = Media::create(['disk' => 'public', 'path' => 'website/shared.png']);
    $version = app(MasterMedia::class)->register($website);
    $copy = app(ProductTransfer::class)->storeImage($product, $bytes, $version);
    $draft['images'] = [['id' => $copy->id, 'alt' => 'Actual fixture']];
    $catalogue->save($product, $draft, $product->version, $owner);
    expect(DB::table('master_media_assets')->count())->toBe(1)
        ->and(DB::table('master_media_usages')->sole()->version_id)->toBe($version);
    $second = UploadedFile::fake()->image('new.png', 20, 20);
    Storage::disk('public')->put('website/shared.png', file_get_contents($second->getRealPath()));
    $this->actingAs($owner);
    Livewire::test(MediaLibrary::class)->call('delete', $website->id)->assertDispatched('toast', type: 'error');
    expect(Media::find($website->id))->not->toBeNull();
    app(MasterMedia::class)->register($website);
    expect(app(MasterMedia::class)->register($copy))->toBe($version)
        ->and(DB::table('master_media_usages')->sole()->version_id)->toBe($version);
});

it('rejects unsafe sources and mismatched master bindings', function () {
    $bad = Media::create(['disk' => 'public', 'path' => '../secret.png']);
    expect(fn () => app(MasterMedia::class)->register($bad))->toThrow(ValidationException::class);
    expect(fn () => app(MasterMedia::class)->linkProduct(new ProductMedia(['sha256' => str_repeat('0', 64)]), 999))->toThrow(ValidationException::class);
});

it('indexes existing sources and revision usages idempotently without publishing', function () {
    $owner = $this->userWithRole('owner');
    $product = app(ProductCatalogue::class)->save(null, array_replace(app(ProductCatalogue::class)->defaults(), ['name' => 'Existing source', 'slug' => 'existing-source']), 0, $owner);
    $file = UploadedFile::fake()->image('existing.png');
    $bytes = file_get_contents($file->getRealPath());
    $path = 'commerce/product-media/'.hash('sha256', $bytes).'.png';
    Storage::disk('local')->put($path, $bytes);
    $media = ProductMedia::create(['product_id' => $product->id, 'path' => $path, 'sha256' => hash('sha256', $bytes)]);
    DB::table('commerce_product_revisions')->insert(['product_id' => $product->id, 'content' => json_encode(['images' => [['id' => $media->id, 'alt' => 'Actual fixture']]]), 'created_by' => $owner->id, 'created_at' => now()]);
    expect($this->artisan('media:index-masters'))->toBe(0);
    expect($this->artisan('media:index-masters'))->toBe(0);
    expect(DB::table('master_media_assets')->count())->toBe(1)
        ->and(DB::table('master_media_versions')->count())->toBe(1)
        ->and(DB::table('master_media_usages')->count())->toBe(1)
        ->and($product->fresh()->published_revision_id)->toBeNull()
        ->and(Storage::disk('local')->get($path))->toBe($bytes);
});
