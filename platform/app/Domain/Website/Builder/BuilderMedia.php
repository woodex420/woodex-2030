<?php

declare(strict_types=1);

namespace App\Domain\Website\Builder;

use App\Domain\Website\Models\Media;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The builder's media picker, backed by the OS media library.
 *
 * The builder only needs URLs, but every image it uploads must become a normal `website_media` row —
 * so the Media screen can give it alt text, and the SEO checks can see it (D-66).
 */
final class BuilderMedia
{
    /**
     * Newest first, as the picker shows them.
     *
     * @return array<int, string>
     */
    public function urls(): array
    {
        return Media::query()
            ->latest('id')
            ->limit(300)
            ->get()
            ->map(fn (Media $media): string => $media->url())
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function upload(?UploadedFile $file, ?User $user): array
    {
        Validator::make(['file' => $file], [
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,avif', 'max:5120'],
        ], [
            'file.required' => 'Choose an image first.',
            'file.mimes' => 'Only JPG, PNG, WebP or AVIF images can be uploaded.',
            'file.max' => 'The image must be smaller than 5 MB.',
        ])->validate();

        /** @var UploadedFile $file */
        $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $folder = 'website/'.now()->format('Y/m');
        $path = $folder.'/'.Str::slug($name).'-'.Str::lower(Str::random(6)).'.'.$file->extension();

        Storage::disk('public')->put($path, file_get_contents($file->getRealPath()));
        [$width, $height] = @getimagesize($file->getRealPath()) ?: [null, null];

        $media = Media::query()->create([
            'disk' => 'public',
            'path' => $path,
            'alt' => Str::headline($name),
            'title' => Str::headline($name),
            'width' => $width,
            'height' => $height,
            'folder' => $folder,
            'uploaded_by' => $user?->id,
        ]);

        return [
            'ok' => true,
            'url' => $media->url(),
            'width' => $width,
            'height' => $height,
            'id' => $media->id,
        ];
    }
}
