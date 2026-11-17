<?php

declare(strict_types=1);

namespace App\Domain\Media\Services;

use App\Domain\Commerce\Models\ProductMedia;
use App\Domain\Website\Models\Media;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Internal registry only: caller must authorize the consumer. All retained masters stay private. */
final class MasterMedia
{
    public function register(Media|ProductMedia $source): int
    {
        $website = $source instanceof Media;
        $type = $website ? 'website' : 'product_media';
        $diskName = $website ? $source->disk : 'local';
        $allowed = $website ? in_array($diskName, ['public', 'site'], true) : str_starts_with($source->path, 'commerce/product-media/');
        if (! $allowed || preg_match('~(^|/)\.\.?(/|$)|[\\\\\x00]~', $source->path)) {
            throw ValidationException::withMessages(['media' => 'Unsafe media source.']);
        }
        $disk = Storage::disk($diskName);
        $root = $allowed ? realpath($disk->path('')) : false;
        $path = $allowed ? realpath($disk->path($source->path)) : false;
        if (! $root || ! $path || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || preg_match('~(^|/)\.\.?(/|$)|[\\\\\x00]~', $source->path) || ! is_file($path) || filesize($path) > 5 * 1024 * 1024) {
            throw ValidationException::withMessages(['media' => 'Media source is missing, unsafe or larger than 5 MB.']);
        }
        $bytes = file_get_contents($path);
        $info = @getimagesizefromstring($bytes);
        $formats = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/avif' => 'avif', 'image/gif' => 'gif'];
        if (! $info || ! isset($formats[$info['mime']]) || $info[0] > 6000 || $info[1] > 6000) {
            throw ValidationException::withMessages(['media' => 'Media must be a supported raster image at most 6000 × 6000.']);
        }
        $hash = hash('sha256', $bytes);
        $retained = 'media/masters/'.$hash.'.'.$formats[$info['mime']];
        if (! Storage::disk('local')->exists($retained) && ! Storage::disk('local')->put($retained, $bytes)) {
            throw ValidationException::withMessages(['media' => 'Could not retain master image.']);
        }

        return DB::transaction(function () use ($source, $website, $type, $hash, $retained, $info, $bytes): int {
            // An explicitly linked product copy is pinned, not reassigned to its former standalone asset.
            $link = DB::table('master_media_sources')->where('source_type', $type)->where('source_id', $source->id)->first();
            if (! $website && $link) {
                $version = DB::table('master_media_versions')->find($link->version_id);
                if ($version && hash_equals($version->sha256, $hash)) {
                    return (int) $version->id;
                }
            }
            DB::table('master_media_assets')->insertOrIgnore(['source_type' => $type, 'source_id' => $source->id, 'title' => $website ? ($source->title ?? '') : 'Product image '.$source->id, 'folder' => $website ? ($source->folder ?? 'website/general') : 'website/products', 'created_at' => now(), 'updated_at' => now()]);
            $asset = DB::table('master_media_assets')->where('source_type', $type)->where('source_id', $source->id)->lockForUpdate()->first();
            DB::table('master_media_versions')->insertOrIgnore(['asset_id' => $asset->id, 'sha256' => $hash, 'path' => $retained, 'mime' => $info['mime'], 'bytes' => strlen($bytes), 'width' => $info[0], 'height' => $info[1], 'created_at' => now()]);
            $versionId = (int) DB::table('master_media_versions')->where('asset_id', $asset->id)->where('sha256', $hash)->value('id');
            DB::table('master_media_sources')->updateOrInsert(['source_type' => $type, 'source_id' => $source->id], ['version_id' => $versionId, 'created_at' => now(), 'updated_at' => now()]);

            return $versionId;
        });
    }

    public function linkProduct(ProductMedia $copy, int $versionId): void
    {
        $version = DB::table('master_media_versions')->find($versionId);
        if (! $version || ! hash_equals($version->sha256, $copy->sha256)) {
            throw ValidationException::withMessages(['media' => 'Product bytes do not match the selected master version.']);
        }
        DB::table('master_media_sources')->updateOrInsert(['source_type' => 'product_media', 'source_id' => $copy->id], ['version_id' => $versionId, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function recordRevision(int $productId, int $revisionId, array $images): void
    {
        foreach ($images as $slot => $image) {
            $media = ProductMedia::where('product_id', $productId)->findOrFail($image['id']);
            $version = $this->register($media);
            DB::table('master_media_usages')->insertOrIgnore(['version_id' => $version, 'product_id' => $productId, 'revision_id' => $revisionId, 'slot' => $slot]);
        }
    }
}
