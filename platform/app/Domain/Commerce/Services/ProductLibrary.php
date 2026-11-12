<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Services;

use App\Domain\Website\Models\Media;
use Illuminate\Support\Facades\Storage;

final class ProductLibrary
{
    public function websitePath(Media $media): string
    {
        abort_unless($media->disk === 'public' && str_starts_with($media->path, 'website/') && ! preg_match('~(^|/)\.\.?(/|$)|[\\\\\x00]~', $media->path), 404);
        $root = realpath(Storage::disk('public')->path(''));
        $path = realpath(Storage::disk('public')->path($media->path));
        abort_unless($root && $path && str_starts_with($path, $root.DIRECTORY_SEPARATOR) && is_file($path) && filesize($path) <= 4 * 1024 * 1024, 404);
        $info = @getimagesize($path);
        abort_unless($info && in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) && $info[0] <= 6000 && $info[1] <= 6000, 404);

        return $path;
    }
}
