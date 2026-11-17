<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Website\Models\Media;
use Illuminate\Support\Facades\Storage;

final class WebsiteLibraryImageController
{
    public function show(Media $media)
    {
        abort_unless(in_array($media->disk, ['public', 'site'], true), 404);
        abort_if(preg_match('~(^|/)\.\.?(/|$)|[\\\\\x00]~', $media->path), 404);
        $disk = Storage::disk($media->disk);
        $root = realpath($disk->path(''));
        $path = realpath($disk->path($media->path));
        abort_unless($root && $path && str_starts_with($path, $root.DIRECTORY_SEPARATOR) && is_file($path), 404);
        $info = @getimagesize($path);
        abort_unless($info && in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif'], true), 404);

        return response()->file($path, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->setPrivate();
    }
}
