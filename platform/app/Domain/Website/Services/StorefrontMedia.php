<?php

declare(strict_types=1);

namespace App\Domain\Website\Services;

use App\Domain\Media\Services\MasterMedia;
use App\Domain\Website\Models\Media;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Integration adapter for the existing website_media library, not a second library. */
final class StorefrontMedia
{
    public function import(): array
    {
        $root = realpath((string) config('woodex.catalogue_source_image_root'));
        if (! $root || ! is_dir($root)) {
            throw ValidationException::withMessages(['media' => 'React image source folder is unavailable. Configure CATALOGUE_SOURCE_IMAGE_ROOT.']);
        }
        $catalogue = json_decode(file_get_contents(resource_path('data/product-import-source.json')), true, flags: JSON_THROW_ON_ERROR);
        $productKeys = array_unique(array_merge(...array_column($catalogue['items'], 'images')));
        $added = $skipped = 0;
        foreach (glob($root.'/*') ?: [] as $path) {
            $name = basename($path);
            if (! preg_match('/^[a-zA-Z0-9_.-]+\.(?:jpe?g|png|webp|avif|gif)$/i', $name)) {
                continue;
            }
            $real = realpath($path);
            if (! $real || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR) || ! is_file($real) || filesize($real) > 5 * 1024 * 1024) {
                $skipped++;

                continue;
            }
            $existing = Media::where('storefront_key', '/img/'.$name)->first();
            if ($existing) {
                if ($existing->folder === 'website/storefront' && in_array('/img/'.$name, $productKeys, true)) {
                    $existing->forceFill(['folder' => 'website/products'])->save();
                    DB::table('master_media_assets')->where('source_type', 'website')->where('source_id', $existing->id)->update(['folder' => 'website/products', 'updated_at' => now()]);
                }

                continue;
            }
            $info = @getimagesize($real);
            $formats = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/avif' => 'avif', 'image/gif' => 'gif'];
            if (! $info || ! isset($formats[$info['mime']]) || $info[0] > 6000 || $info[1] > 6000) {
                $skipped++;

                continue;
            }
            $bytes = file_get_contents($real);
            $stored = 'website/react-originals/'.hash('sha256', $bytes).'.'.$formats[$info['mime']];
            if (! Storage::disk('public')->put($stored, $bytes)) {
                throw ValidationException::withMessages(['media' => 'Image storage failed.']);
            }
            // Identical files share storage, but each original React key remains independently replaceable.
            $media = Media::firstOrCreate(['disk' => 'public', 'path' => $stored], ['title' => $name, 'alt' => '', 'folder' => in_array('/img/'.$name, $productKeys, true) ? 'website/products' : 'website/storefront', 'width' => $info[0], 'height' => $info[1]]);
            if ($media->storefront_key && $media->storefront_key !== '/img/'.$name) {
                $aliasPath = 'website/react-originals/'.hash('sha256', $name.$bytes).'.'.$formats[$info['mime']];
                Storage::disk('public')->put($aliasPath, $bytes);
                $media = Media::firstOrCreate(['disk' => 'public', 'path' => $aliasPath], ['title' => $name, 'alt' => '', 'folder' => in_array('/img/'.$name, $productKeys, true) ? 'website/products' : 'website/storefront', 'width' => $info[0], 'height' => $info[1]]);
            }
            $media->forceFill(['storefront_key' => '/img/'.$name])->save();
            app(MasterMedia::class)->register($media);
            $added++;
        }

        return compact('added', 'skipped');
    }

    public function sourceReferences(?Media $media): array
    {
        if (! $media?->storefront_key) {
            return [];
        }
        $source = json_decode(file_get_contents(resource_path('data/product-import-source.json')), true, flags: JSON_THROW_ON_ERROR);

        return array_values(array_map(fn ($item) => $item['name'], array_filter($source['items'], fn ($item) => in_array($media->storefront_key, $item['images'], true))));
    }

    public function replace(Media $media, string $bytes, string $expectedPath, bool $publish, User $user): void
    {
        abort_unless($user->can('website.edit'), 403);
        abort_if($publish && ! $user->can('website.publish'), 403);
        $info = @getimagesizefromstring($bytes);
        $formats = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (! $info || ! isset($formats[$info['mime']]) || strlen($bytes) > 4 * 1024 * 1024 || $info[0] > 6000 || $info[1] > 6000) {
            throw ValidationException::withMessages(['replacement' => 'Choose a genuine JPEG, PNG or WebP up to 4 MB and 6000 × 6000.']);
        }
        DB::transaction(function () use ($media, $bytes, $expectedPath, $publish, $info, $formats, $user): void {
            $locked = Media::lockForUpdate()->findOrFail($media->id);
            if ($locked->path !== $expectedPath) {
                throw ValidationException::withMessages(['replacement' => 'This image changed. Close and reopen the replacement dialog.']);
            }
            if ($publish && ! $locked->storefront_key) {
                throw ValidationException::withMessages(['replacement' => 'Only imported React image keys can publish a storefront replacement.']);
            }
            app(MasterMedia::class)->register($locked); // retain old bytes before changing any reference
            $path = 'website/replacements/'.hash('sha256', $bytes).'.'.$formats[$info['mime']];
            // Same bytes may be reused by multiple media identities; keep unique disk/path rows.
            $pathForRow = 'website/replacements/'.$locked->id.'-'.basename($path);
            if (! Storage::disk('public')->put($pathForRow, $bytes)) {
                throw ValidationException::withMessages(['replacement' => 'Image storage failed.']);
            }
            $locked->forceFill(['disk' => 'public', 'path' => $pathForRow, 'width' => $info[0], 'height' => $info[1]])->save();
            $version = app(MasterMedia::class)->register($locked);
            if ($publish) {
                $locked->forceFill(['storefront_version_id' => $version])->save();
            }
            PageCache::invalidateAll($locked->pagesUsing());
            activity('website')->performedOn($locked)->causedBy($user)->log($publish ? 'React shared image replacement published' : 'Library image replaced; React publication unchanged');
        });
    }
}
