<?php

use App\Domain\Website\Models\Media;
use App\Domain\Website\Services\StorefrontMedia;
use App\Livewire\Admin\Website\MediaLibrary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
    $this->owner = $this->userWithRole('owner');
    $this->file = UploadedFile::fake()->image('original.png', 20, 20);
    Storage::disk('public')->put('website/original.png', file_get_contents($this->file->getRealPath()));
    $this->media = Media::create(['disk' => 'public', 'path' => 'website/original.png', 'title' => 'Original']);
    $this->media->forceFill(['storefront_key' => '/img/original.png'])->save();
});

it('exposes only explicitly published replacements and keeps library-only changes off the manifest', function () {
    $this->getJson('/api/v1/media')->assertOk()->assertExactJson(['schema' => 1, 'items' => []]);
    $new = UploadedFile::fake()->image('new.png', 30, 30);
    $service = app(StorefrontMedia::class);
    $service->replace($this->media, file_get_contents($new->getRealPath()), $this->media->path, false, $this->owner);
    $this->getJson('/api/v1/media')->assertJsonCount(0, 'items');
    $this->media->refresh();
    $service->replace($this->media, file_get_contents($new->getRealPath()), $this->media->path, true, $this->owner);
    $id = $this->media->fresh()->storefront_version_id;
    $this->getJson('/api/v1/media')->assertJsonPath('items.0.key', '/img/original.png')->assertJsonPath('items.0.url', '/api/v1/media/'.$this->media->id.'/'.$id);
    $this->get('/api/v1/media/'.$this->media->id.'/'.$id)->assertOk();
    $this->get('/api/v1/media/'.$this->media->id.'/999999')->assertNotFound();
    Storage::disk('public')->assertExists('website/original.png');
    expect(DB::table('master_media_versions')->count())->toBe(2);
    $this->actingAs($this->owner);
    Livewire::test(MediaLibrary::class)->call('withdrawReplacement', $this->media->id)->assertHasNoErrors();
    $this->getJson('/api/v1/media')->assertJsonCount(0, 'items');
    Storage::disk('public')->assertExists($this->media->fresh()->path);
    $this->get('/api/v1/media/'.$this->media->id.'/'.$id)->assertNotFound();
});

it('requires confirmation and publish permission and rejects stale replacement dialogs', function () {
    $this->actingAs($this->owner);
    Livewire::test(MediaLibrary::class)->call('chooseReplacement', $this->media->id)->call('replaceImage')->assertHasErrors('confirmReplacement');
    $new = UploadedFile::fake()->image('new.png', 30, 30);
    $service = app(StorefrontMedia::class);
    expect(fn () => $service->replace($this->media, file_get_contents($new->getRealPath()), 'stale.png', true, $this->owner))->toThrow(ValidationException::class);
    $editor = $this->userWithRole('admin');
    $editor->roles->each(fn ($role) => $role->revokePermissionTo('website.publish'));
    $this->actingAs($editor->fresh());
    Livewire::test(MediaLibrary::class)->call('chooseReplacement', $this->media->id)->set('replacementUpload', $new)->set('publishReplacement', true)->set('confirmReplacement', true)->call('replaceImage')->assertForbidden();
});

it('reuses an existing library image and blocks deleting connected images', function () {
    $other = Media::create(['disk' => 'public', 'path' => 'website/reuse.png']);
    Storage::disk('public')->put($other->path, file_get_contents($this->file->getRealPath()));
    $this->actingAs($this->owner);
    Livewire::test(MediaLibrary::class)->call('chooseReplacement', $this->media->id)->set('reuseId', (string) $other->id)->set('confirmReplacement', true)->call('replaceImage')->assertHasNoErrors()->assertSet('showReplacement', false);
    Livewire::test(MediaLibrary::class)->call('delete', $this->media->id)->assertDispatched('toast', type: 'error');
    expect($this->media->fresh())->not->toBeNull();
});

it('imports React originals idempotently without changing source files or publishing', function () {
    $root = Storage::disk('local')->path('react-source');
    mkdir($root);
    file_put_contents($root.'/real.png', file_get_contents($this->file->getRealPath()));
    config(['woodex.catalogue_source_image_root' => $root]);
    expect(app(StorefrontMedia::class)->import()['added'])->toBe(1);
    expect(app(StorefrontMedia::class)->import()['added'])->toBe(0);
    expect(Media::where('storefront_key', '/img/real.png')->sole()->storefront_version_id)->toBeNull();
    expect(file_get_contents($root.'/real.png'))->toBe(file_get_contents($this->file->getRealPath()));
});
