<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Website\Models\Media;
use App\Http\Controllers\Controller;
use App\Support\Api\ApiAnswer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * GET /api/v1/media: the photographs of the shop that were replaced in the admin and published, each as "the
 * shop's own file → the address of its replacement". Only explicitly published replacements; never private
 * product images or the whole library. Always the whole list: the shop swaps pictures from it.
 *
 * GET /api/v1/media/{media}/{version}: one such replacement.
 */
final class MediaController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiAnswer::whole(
            Media::whereNotNull('storefront_key')->whereNotNull('storefront_version_id')->orderBy('id')->get()
                ->map(fn (Media $media): array => ['key' => (string) $media->storefront_key, 'url' => '/api/v1/media/'.$media->id.'/'.$media->storefront_version_id])
                ->all(),
        );
    }

    public function file(Media $media, int $version): BinaryFileResponse
    {
        abort_unless($media->storefront_key && (int) $media->storefront_version_id === $version, 404);
        $asset = DB::table('master_media_assets')->where('source_type', 'website')->where('source_id', $media->id)->first();
        $row = $asset ? DB::table('master_media_versions')->where('asset_id', $asset->id)->where('id', $version)->first() : null;
        abort_unless($row && preg_match('~^media/masters/[a-f0-9]{64}\.(jpg|png|webp)$~', $row->path) && Storage::disk('local')->exists($row->path), 404);

        return response()->file(Storage::disk('local')->path($row->path), ['Content-Type' => $row->mime, 'Cache-Control' => 'public, max-age=30', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow']);
    }
}
