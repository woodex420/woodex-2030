<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Private attachment download (GLB-006): only people who can see the lead/contact get the file.
 * Images and PDFs open in the browser; everything else downloads.
 */
final class MediaController
{
    public function __invoke(Request $request, Media $media, ?string $conversion = null): Response|StreamedResponse|BinaryFileResponse
    {
        $owner = $media->model;
        abort_if($owner === null || Gate::denies('view', $owner), 403);

        if ($conversion !== null) {
            abort_unless($conversion === 'thumb' && $media->hasGeneratedConversion('thumb'), 404);

            return response()->file($media->getPath('thumb'), [
                'Content-Type' => 'image/webp',
                'Cache-Control' => 'private, max-age=86400',
            ]);
        }

        $inline = str_starts_with((string) $media->mime_type, 'image/') || $media->mime_type === 'application/pdf';

        return ($inline && ! $request->boolean('download'))
            ? $media->toInlineResponse($request)
            : $media->toResponse($request);
    }
}
