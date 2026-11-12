<?php

use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\ProductMedia;
use App\Domain\Commerce\Services\ProductCatalogue;
use App\Domain\Commerce\Services\ProductTransfer;
use App\Domain\Website\Models\Media;
use App\Livewire\Admin\Commerce\ProductEditor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function galleryFixture(int $size = 20): string
{
    $file = UploadedFile::fake()->image('fixture.png', $size, $size);

    return file_get_contents($file->getRealPath());
}

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->editor = $this->userWithRole('admin');
    $this->actingAs($this->editor);
    $this->product = app(ProductCatalogue::class)->save(null, array_replace(app(ProductCatalogue::class)->defaults(), ['name' => 'Gallery test', 'slug' => 'gallery-test', 'reviewed' => true]), 0, $this->editor);
    $this->first = Media::create(['disk' => 'public', 'path' => 'website/one.png', 'title' => 'First fixture', 'alt' => 'First fixture alt', 'folder' => 'website/products']);
    $this->second = Media::create(['disk' => 'public', 'path' => 'website/two.png', 'title' => 'Second fixture', 'alt' => 'Second fixture alt', 'folder' => 'website/products']);
    Storage::disk('public')->put($this->first->path, galleryFixture(20));
    Storage::disk('public')->put($this->second->path, galleryFixture(30));
});

it('stages multiple images in chosen order and saves only on explicit Save draft', function () {
    $component = Livewire::test(ProductEditor::class, ['product' => $this->product])->call('openMediaLibrary')
        ->set('librarySelection', ['website:'.$this->first->id, 'website:'.$this->second->id])->call('moveLibrarySelection', 1, -1)
        ->call('addSelectedLibraryImages')->assertHasNoErrors()->assertSet('showMediaLibrary', false)->assertSet('librarySelection', [])->assertSet('form.reviewed', false)
        ->assertSet('form.images.0.alt', 'Second fixture alt')->assertSet('form.images.1.alt', 'First fixture alt');
    expect($this->product->fresh()->draft['images'])->toBe([])->and($this->product->fresh()->version)->toBe($this->product->version);
    $component->set('confirmPublish', true)->call('publish')->assertHasErrors('publish')->call('saveDraft')->assertHasNoErrors();
    expect($this->product->fresh()->draft['images'])->toHaveCount(2)->and($this->product->fresh()->published_revision_id)->toBeNull();
    Storage::disk('public')->assertExists($this->first->path);
});

it('deduplicates repeated content and preserves existing primary image when appending', function () {
    $media = app(ProductTransfer::class)->storeImage($this->product, galleryFixture(20));
    $data = $this->product->draft;
    $data['images'] = [['id' => $media->id, 'alt' => 'Keep primary']];
    $product = app(ProductCatalogue::class)->save($this->product, $data, $this->product->version, $this->editor);
    Livewire::test(ProductEditor::class, ['product' => $product])->call('openMediaLibrary')
        ->set('librarySelection', ['website:'.$this->first->id, 'website:'.$this->second->id])->call('addSelectedLibraryImages')->assertHasNoErrors()
        ->assertSet('form.images.0.id', $media->id)->assertSet('form.images.0.alt', 'Keep primary')->assertCount('form.images', 2);
});

it('rolls back all references if a later selected file is missing', function () {
    $before = DB::table('master_media_sources')->count();
    Livewire::test(ProductEditor::class, ['product' => $this->product])->call('openMediaLibrary')
        ->set('librarySelection', ['website:'.$this->first->id, 'website:999999'])->call('addSelectedLibraryImages')->assertNotFound();
    expect(ProductMedia::where('product_id', $this->product->id)->count())->toBe(0)
        ->and($this->product->fresh()->draft['images'])->toBe([])->and(DB::table('master_media_sources')->count())->toBe($before);
});

it('enforces website permission for selection and details and denies viewer mutation', function () {
    $this->editor->roles->each(fn ($role) => $role->revokePermissionTo('website.edit'));
    $this->actingAs($this->editor->fresh());
    Livewire::test(ProductEditor::class, ['product' => $this->product])->call('openMediaLibrary')->set('librarySelection', ['website:'.$this->first->id])->call('addSelectedLibraryImages')->assertForbidden();
    Livewire::test(ProductEditor::class, ['product' => $this->product])->call('inspectLibraryItem', 'website:'.$this->first->id)->assertForbidden();
    $this->actingAs($this->userWithRole('sales'));
    Livewire::test(ProductEditor::class, ['product' => $this->product])->assertForbidden();
});

it('retains selections across searches and pagination and allows older supported images to be reached', function () {
    for ($i = 0; $i < 26; $i++) {
        Media::create(['disk' => 'public', 'path' => 'website/missing-'.$i.'.png', 'title' => 'Missing fixture']);
    }
    Livewire::test(ProductEditor::class, ['product' => $this->product])->call('openMediaLibrary')
        ->set('librarySelection', ['website:'.$this->first->id])->set('mediaSearch', 'fixture')->assertSet('librarySelection', ['website:'.$this->first->id])
        ->assertViewHas('websiteLibrary', fn ($page) => $page->count() === 0 && $page->hasMorePages())
        ->call('setPage', 2, 'websiteMediaPage')->assertViewHas('websiteLibrary', fn ($page) => $page->count() === 2)
        ->call('inspectLibraryItem', 'website:'.$this->first->id)->assertSet('libraryDetails.width', 20)->assertSet('libraryDetails.alt', 'First fixture alt');
});

it('rejects stale edits forged tokens empty selections and batches above the gallery limit', function () {
    $component = Livewire::test(ProductEditor::class, ['product' => $this->product])->call('openMediaLibrary');
    $component->call('addSelectedLibraryImages')->assertHasErrors('librarySelection');
    $component->set('librarySelection', ['crm:1'])->call('addSelectedLibraryImages')->assertHasErrors('librarySelection.0');
    $component->set('librarySelection', array_map(fn ($id) => 'website:'.$id, range(1, 25)))->call('addSelectedLibraryImages')->assertHasErrors('librarySelection');
    app(ProductCatalogue::class)->save($this->product, $this->product->draft, $this->product->version, $this->editor);
    $component->set('librarySelection', ['website:'.$this->first->id])->call('addSelectedLibraryImages')->assertHasErrors('conflict');
});

it('rolls back excess gallery additions without losing existing slots', function () {
    $data = $this->product->draft;
    for ($i = 0; $i < 24; $i++) {
        $media = app(ProductTransfer::class)->storeImage($this->product, galleryFixture(50 + $i));
        $data['images'][] = ['id' => $media->id, 'alt' => 'Actual fixture'];
    }
    $product = app(ProductCatalogue::class)->save($this->product, $data, $this->product->version, $this->editor);
    Livewire::test(ProductEditor::class, ['product' => $product])->call('openMediaLibrary')->set('librarySelection', ['website:'.$this->first->id])->call('addSelectedLibraryImages')
        ->assertHasErrors('librarySelection')->assertCount('form.images', 24)->assertSet('showMediaLibrary', true);
    expect(ProductMedia::where('product_id', $product->id)->count())->toBe(24)->and($product->fresh()->draft['images'])->toHaveCount(24);
});

it('does not expose archived-only photos in the picker while retaining their original files', function () {
    $archived = app(ProductCatalogue::class)->save(null, array_replace(app(ProductCatalogue::class)->defaults(), ['name' => 'Archived source', 'slug' => 'archived-gallery-source']), 0, $this->editor);
    $media = app(ProductTransfer::class)->storeImage($archived, galleryFixture(55));
    $archived->update(['archived_at' => now()]);
    Livewire::test(ProductEditor::class, ['product' => $this->product])->call('openMediaLibrary')
        ->assertViewHas('library', fn ($page) => ! $page->getCollection()->contains('id', $media->id));
    Storage::disk('local')->assertExists($media->path);
});

it('rolls back a newly created private owner record if gallery selection fails', function () {
    $draft = array_replace(app(ProductCatalogue::class)->defaults(), ['name' => 'New unsaved gallery', 'slug' => 'new-unsaved-gallery']);
    Livewire::test(ProductEditor::class)->set('form', $draft)->call('openMediaLibrary')
        ->set('librarySelection', ['website:'.$this->first->id, 'website:999999'])->call('addSelectedLibraryImages')->assertNotFound();
    expect(Product::where('slug', 'new-unsaved-gallery')->exists())->toBeFalse();
});
