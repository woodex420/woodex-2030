<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Domain\Website\Models\Media;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class StorefrontMediaController
{
    public function index()
    {
        return response()->json(['schema' => 1, 'items' => Media::whereNotNull('storefront_key')->whereNotNull('storefront_version_id')->get()->map(fn ($media) => ['key' => $media->storefront_key, 'url' => '/api/v1/media/'.$media->id.'/'.$media->storefront_version_id])->values()])->header('Cache-Control', 'no-store');
    }

    public function image(Media $media, int $version)
    {
        abort_unless($media->storefront_key && (int) $media->storefront_version_id === $version, 404);
        $asset = DB::table('master_media_assets')->where('source_type', 'website')->where('source_id', $media->id)->first();
        $row = $asset ? DB::table('master_media_versions')->where('asset_id', $asset->id)->find($version) : null;
        abort_unless($row && preg_match('~^media/masters/[a-f0-9]{64}\.(jpg|png|webp)$~', $row->path) && Storage::disk('local')->exists($row->path), 404);

        return response()->file(Storage::disk('local')->path($row->path), ['Content-Type' => $row->mime, 'Cache-Control' => 'public, max-age=30', 'X-Content-Type-Options' => 'nosniff']);
    }
}
