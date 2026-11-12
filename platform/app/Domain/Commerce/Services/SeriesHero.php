<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class SeriesHero
{
    public function store(string $bytes): string
    {
        $info = @getimagesizefromstring($bytes);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (strlen($bytes) > 4 * 1024 * 1024 || ! $info || ! isset($extensions[$info['mime']]) || $info[0] > 6000 || $info[1] > 6000) {
            throw ValidationException::withMessages(['hero' => 'Choose a real JPEG, PNG or WebP image up to 4 MB and 6000 pixels per dimension.']);
        }
        $path = 'commerce/series-heroes/'.hash('sha256', $bytes).'.'.$extensions[$info['mime']];
        if (! Storage::disk('local')->put($path, $bytes)) {
            throw ValidationException::withMessages(['hero' => 'Private hero storage failed. Try again before saving.']);
        }

        return $path;
    }

    public function path(string $path): string
    {
        abort_unless(preg_match('~^commerce/series-heroes/([a-f0-9]{64})\.(jpg|png|webp)$~', $path, $match), 404);
        $root = realpath(Storage::disk('local')->path(''));
        $file = realpath(Storage::disk('local')->path($path));
        abort_unless($root && $file && str_starts_with($file, $root.DIRECTORY_SEPARATOR) && is_file($file) && filesize($file) <= 4 * 1024 * 1024, 404);
        abort_unless(hash_equals($match[1], hash_file('sha256', $file)), 404);
        $info = @getimagesize($file);
        $mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$match[2]];
        abort_unless($info && $info['mime'] === $mime && $info[0] <= 6000 && $info[1] <= 6000, 404);

        return $file;
    }
}
