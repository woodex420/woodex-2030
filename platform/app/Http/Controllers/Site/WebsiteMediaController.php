<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Domain\Website\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves an image from the website media library (docs/09 §2.1). Uploads live in storage/ outside the web
 * folder — on shared hosting a deployment replaces the web folder, and files saved inside it would be lost —
 * so they are read from here instead of being linked directly.
 *
 * The URL carries the image id and the time it last changed, which makes it safe to cache forever.
 */
final class WebsiteMediaController
{
    public function __invoke(Request $request, Media $media, string $name): Response|StreamedResponse
    {
        $disk = Storage::disk($media->disk);

        abort_unless($disk->exists($media->path), 404);

        $response = $disk->response($media->path, null, [
            'Content-Type' => $disk->mimeType($media->path) ?: 'application/octet-stream',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);

        return $response->setEtag(md5($media->id.'.'.$media->updated_at?->timestamp.'.'.$name));
    }
}
