<?php

declare(strict_types=1);

use App\Domain\Website\Models\Media;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
| The builder's media picker (Slice 2). Every image it uploads becomes a normal website_media row,
| so the OS Media screen owns alt text and the SEO checks can see the file.
*/

beforeEach(function () {
    Storage::fake('public');

    $this->editor = User::factory()->create();
    $this->editor->givePermissionTo(['website.edit', 'website.publish']);
});

function mediaUpload(User $user, UploadedFile $file)
{
    return test()->actingAs($user)->post(
        route('admin.website.builder.api'),
        ['action' => 'upload', 'file' => $file],
        ['Accept' => 'application/json'],
    );
}

it('lists the media library, newest first', function () {
    Media::query()->create(['disk' => 'public', 'path' => 'website/2026/01/older.jpg', 'alt' => 'Older']);
    Media::query()->create(['disk' => 'public', 'path' => 'website/2026/02/newer.jpg', 'alt' => 'Newer']);

    $urls = test()->actingAs($this->editor)
        ->postJson(route('admin.website.builder.api'), ['action' => 'media'])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->json('media');

    expect($urls)->toHaveCount(2)
        ->and($urls[0])->toContain('newer.jpg')
        ->and($urls[1])->toContain('older.jpg');
});

it('stores a builder upload as a normal media row with its size', function () {
    $file = UploadedFile::fake()->image('living room.png', 1200, 800);

    $response = mediaUpload($this->editor, $file)->assertOk()->assertJsonPath('ok', true);

    $media = Media::query()->sole();

    expect($media->disk)->toBe('public')
        ->and($media->path)->toStartWith('website/')
        ->and($media->path)->toEndWith('.png')
        ->and($media->width)->toBe(1200)
        ->and($media->height)->toBe(800)
        ->and($media->alt)->toBe('Living Room')
        ->and(Storage::disk('public')->exists($media->path))->toBeTrue()
        ->and($response->json('width'))->toBe(1200)
        ->and($response->json('url'))->toContain($media->id);
});

it('refuses a file that is not an image', function () {
    $file = UploadedFile::fake()->create('invoice.pdf', 20, 'application/pdf');

    mediaUpload($this->editor, $file)
        ->assertStatus(422)
        ->assertJsonPath('ok', false);

    expect(Media::query()->count())->toBe(0);
});

it('refuses an image over five megabytes', function () {
    $file = UploadedFile::fake()->image('huge.jpg')->size(6000);

    mediaUpload($this->editor, $file)->assertStatus(422);

    expect(Media::query()->count())->toBe(0);
});

it('keeps uploads to website editors', function () {
    $stranger = User::factory()->create();

    mediaUpload($stranger, UploadedFile::fake()->image('photo.jpg'))->assertForbidden();
});
