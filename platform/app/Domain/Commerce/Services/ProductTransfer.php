<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Services;

use App\Domain\Commerce\Models\Category;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\ProductMedia;
use App\Domain\Commerce\Models\Series;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ProductTransfer
{
    public function __construct(private ProductCatalogue $catalogue) {}

    public function source(): array
    {
        return json_decode(file_get_contents(resource_path('data/product-import-source.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function sourceSummary(): array
    {
        $source = $this->source();
        $keys = Product::whereNotNull('source_key')->pluck('source_key')->all();

        return ['total' => count($source['items']), 'new' => count(array_filter($source['items'], fn ($p) => ! in_array($p['id'], $keys, true) && ! Product::where('slug', $p['id'])->exists())),
            'source_hash' => $source['sha256'], 'unique_images' => count(array_unique(array_merge(...array_column($source['items'], 'images'))))];
    }

    private function categoryIds(array $slugs): array
    {
        $aliases = json_decode(file_get_contents(resource_path('data/commerce-taxonomy.json')), true, flags: JSON_THROW_ON_ERROR)['aliases'];

        return Category::whereIn('slug', array_map(fn ($s) => $aliases[$s] ?? $s, $slugs))->pluck('id')->all();
    }

    public function importSource(User $user): int
    {
        $this->catalogue->authorize($user);

        return DB::transaction(function () use ($user) {
            $count = 0;
            $source = $this->source();
            foreach ($source['items'] as $row) {
                if (Product::where('source_key', $row['id'])->orWhere('slug', $row['id'])->exists()) {
                    continue;
                }
                $data = $this->catalogue->defaults();
                $data['name'] = $row['name'];
                $data['slug'] = $row['id'];
                $data['description'] = $row['description'];
                $data['short_description'] = $row['shortDescription'];
                $data['price'] = (string) $row['price'];
                // Source inStock flags are preserved for review, not asserted as verified stock.
                $data['availability'] = 'on_request';
                $data['category_ids'] = $this->categoryIds(array_filter([$row['category'], $row['subcategory'] ?? null]));
                $data['primary_category_id'] = end($data['category_ids']) ?: '';
                $data['series_ids'] = Series::where('slug', $row['series'] ?? '')->pluck('id')->all();
                $data['features'] = $row['features'];
                $data['specifications'] = $row['specifications'];
                $data['warranty'] = collect($row['specifications'])->firstWhere('label', 'Warranty')['value'] ?? '';
                $data['seo_title'] = $row['name'];
                $data['seo_description'] = mb_substr($row['shortDescription'], 0, 320);
                $product = $this->catalogue->save(null, $data, 0, $user);
                $images = [];
                foreach (array_unique($row['images']) as $image) {
                    if (! preg_match('~^/img/([a-zA-Z0-9_-]+\.(jpg|jpeg|png|webp))$~', $image, $match)) {
                        throw ValidationException::withMessages(['import' => 'Unsafe source image reference.']);
                    }
                    $root = config('woodex.catalogue_source_image_root', base_path('../apps/api/public/img'));
                    $file = $root.'/'.$match[1];
                    if (! is_file($file)) {
                        throw ValidationException::withMessages(['import' => 'Source image files are not installed. Configure CATALOGUE_SOURCE_IMAGE_ROOT before importing.']);
                    }
                    $bytes = file_get_contents($file);
                    $media = $this->storeImage($product, $bytes);
                    if (! in_array($media->id, array_column($images, 'id'), true)) {
                        $images[] = ['id' => $media->id, 'alt' => ''];
                    }
                }
                $data['images'] = $images;
                $product = $this->catalogue->save($product, $data, $product->version, $user);
                $product->forceFill(['source_key' => $row['id'], 'source_record' => $row, 'source_hash' => $source['sha256'], 'review_issues' => $row['reviewIssues']])->save();
                $count++;
            }
            activity('commerce')->causedBy($user)->withProperties(['count' => $count, 'source_hash' => $source['sha256']])->log('Repository products imported as unreviewed drafts; no overwrite or publication');

            return $count;
        });
    }

    public function storeImage(Product $product, string $bytes): ProductMedia
    {
        $info = @getimagesizefromstring($bytes);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (strlen($bytes) > 4 * 1024 * 1024 || ! $info || ! isset($extensions[$info['mime']]) || $info[0] > 6000 || $info[1] > 6000) {
            throw ValidationException::withMessages(['images' => 'Images must be genuine JPEG/PNG/WebP, at most 4 MB and 6000 × 6000.']);
        }
        $hash = hash('sha256', $bytes);
        $path = 'commerce/product-media/'.$hash.'.'.$extensions[$info['mime']];
        if (! Storage::disk('local')->exists($path) && ! Storage::disk('local')->put($path, $bytes)) {
            throw ValidationException::withMessages(['images' => 'Image storage failed.']);
        }

        return ProductMedia::firstOrCreate(['product_id' => $product->id, 'sha256' => $hash], ['path' => $path]);
    }

    public function export(array $ids): array
    {
        $items = [];
        $assets = [];
        $total = 0;
        foreach (Product::whereIn('id', $ids)->orderBy('id')->get() as $product) {
            $data = $product->draft;
            $data['category_slugs'] = Category::whereIn('id', $data['category_ids'])->pluck('slug')->all();
            $data['primary_category_slug'] = Category::find($data['primary_category_id'])?->slug;
            $data['series_slugs'] = Series::whereIn('id', $data['series_ids'])->pluck('slug')->all();
            $data['related_slugs'] = Product::whereIn('id', $data['related_ids'])->pluck('slug')->all();
            unset($data['category_ids'], $data['primary_category_id'], $data['series_ids'], $data['related_ids']);
            $data['reviewed'] = false;
            $images = [];
            foreach ($product->draft['images'] as $image) {
                $media = ProductMedia::where('product_id', $product->id)->find($image['id']);
                if (! $media || ! Storage::disk('local')->exists($media->path)) {
                    throw ValidationException::withMessages(['export' => 'A referenced image is missing. Repair it before exporting.']);
                }
                if (! isset($assets[$media->sha256])) {
                    $bytes = Storage::disk('local')->get($media->path);
                    $total += strlen($bytes);
                    if ($total > 16 * 1024 * 1024) {
                        throw ValidationException::withMessages(['export' => 'Media exceeds the portable package limit. Export a smaller selection (16 MB binary media maximum).']);
                    }
                    $assets[$media->sha256] = base64_encode($bytes);
                }
                $images[] = ['asset' => $media->sha256, 'alt' => $image['alt']];
            }
            $data['images'] = $images;
            $items[] = ['draft' => $data, 'source_key' => $product->source_key, 'source_record' => $product->source_record, 'review_issues' => $product->review_issues];
        }

        $package = ['schema' => 'woodex-products-1', 'items' => $items, 'assets' => $assets];
        if (strlen(json_encode($package, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) > 25 * 1024 * 1024 || count($items) > 500) {
            throw ValidationException::withMessages(['export' => 'Export a smaller selection: the portable package must fit the 25 MB / 500 product import limits.']);
        }

        return $package;
    }

    public function parsePackage(string $json): array
    {
        try {
            $package = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['import' => 'Invalid JSON product package.']);
        }
        if (! is_array($package) || ($package['schema'] ?? null) !== 'woodex-products-1' || ! is_array($package['items'] ?? null) || count($package['items']) > 500 || ! is_array($package['assets'] ?? null)) {
            throw ValidationException::withMessages(['import' => 'Use a woodex-products-1 package with at most 500 records.']);
        }
        $seen = [];
        $total = 0;
        foreach ($package['assets'] as $hash => $encoded) {
            $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;
            $total += $bytes === false ? 0 : strlen($bytes);
            $info = $bytes === false ? false : @getimagesizefromstring($bytes);
            if (! preg_match('/^[a-f0-9]{64}$/', (string) $hash) || $bytes === false || hash('sha256', $bytes) !== $hash || strlen($bytes) > 4 * 1024 * 1024 || $total > 16 * 1024 * 1024 || ! $info || ! in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) || $info[0] > 6000 || $info[1] > 6000) {
                throw ValidationException::withMessages(['import' => 'Invalid or oversized image asset in package.']);
            }
        }
        foreach ($package['items'] as &$row) {
            if (! is_array($row) || ! is_array($row['draft'] ?? null)) {
                throw ValidationException::withMessages(['import' => 'Every record needs a product draft.']);
            }
            $data = $row['draft'];
            Validator::make($data, [
                'category_slugs' => ['present', 'array', 'max:30'], 'category_slugs.*' => ['string', 'max:140', 'regex:/^[a-z0-9-]+$/'],
                'primary_category_slug' => ['nullable', 'string', 'max:140'],
                'series_slugs' => ['present', 'array', 'max:20'], 'series_slugs.*' => ['string', 'max:140', 'regex:/^[a-z0-9-]+$/'],
                'related_slugs' => ['present', 'array', 'max:12'], 'related_slugs.*' => ['string', 'max:140', 'regex:/^[a-z0-9-]+$/'],
                'images' => ['present', 'array', 'max:24'],
                'images.*' => ['array'], 'images.*.asset' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'], 'images.*.alt' => ['nullable', 'string', 'max:200'],
            ])->validate();
            $slug = $data['slug'] ?? '';
            if (! is_string($slug) || isset($seen[$slug])) {
                throw ValidationException::withMessages(['import' => 'Duplicate or malformed product slug.']);
            }
            $seen[$slug] = true;
            $sourceKey = $row['source_key'] ?? null;
            if ($sourceKey !== null && (! is_string($sourceKey) || ! preg_match('/^[a-z0-9-]{1,140}$/', $sourceKey))) {
                throw ValidationException::withMessages(['import' => 'Invalid source product key.']);
            }
            $row['skip'] = Product::where('slug', $slug)->exists() || ($sourceKey && Product::where('source_key', $sourceKey)->exists());
            $data['category_ids'] = $this->categoryIds($data['category_slugs'] ?? []);
            $data['primary_category_id'] = $this->categoryIds(array_filter([$data['primary_category_slug'] ?? null]))[0] ?? '';
            $data['series_ids'] = Series::whereIn('slug', $data['series_slugs'] ?? [])->pluck('id')->all();
            $data['related_ids'] = []; // Resolve after all inserts; never trust external native IDs.
            $data['reviewed'] = false;
            foreach ($data['images'] ?? [] as $image) {
                if (! is_array($image) || ! isset($package['assets'][$image['asset'] ?? '']) || ! is_string($image['alt'] ?? '') || mb_strlen($image['alt'] ?? '') > 200) {
                    throw ValidationException::withMessages(['import' => 'Invalid product image association.']);
                }
            }
            $images = $data['images'] ?? [];
            $data['images'] = [];
            $row['clean'] = $this->catalogue->validate($data, $row['skip'] ? Product::where('slug', $slug)->first() : null);
            $row['image_assets'] = $images;
        }
        unset($row);

        return $package;
    }

    public function importPackage(string $json, User $user): int
    {
        $this->catalogue->authorize($user);
        $package = $this->parsePackage($json);

        return DB::transaction(function () use ($package, $user) {
            $count = 0;
            $created = [];
            foreach ($package['items'] as $row) {
                if ($row['skip']) {
                    continue;
                }
                $product = $this->catalogue->save(null, $row['clean'], 0, $user);
                $data = $product->draft;
                foreach ($row['image_assets'] as $image) {
                    $media = $this->storeImage($product, base64_decode($package['assets'][$image['asset']], true));
                    $data['images'][] = ['id' => $media->id, 'alt' => $image['alt'] ?? ''];
                }
                $product = $this->catalogue->save($product, $data, $product->version, $user);
                $product->forceFill(['source_key' => $row['source_key'] ?? null, 'source_record' => is_array($row['source_record'] ?? null) ? $row['source_record'] : null,
                    'review_issues' => ['Portable import: review photos, prices, policy text, classification and variants before publication. Missing category/Series mappings must be reselected.']])->save();
                $created[] = [$product, $row['draft']['related_slugs'] ?? []];
                $count++;
            }
            foreach ($created as [$product, $slugs]) {
                $data = $product->draft;
                $data['related_ids'] = Product::whereIn('slug', $slugs)->where('id', '!=', $product->id)->limit(12)->pluck('id')->all();
                $this->catalogue->save($product, $data, $product->version, $user);
            }
            activity('commerce')->causedBy($user)->withProperties(['count' => $count])->log('Portable product package imported as drafts; existing records skipped');

            return $count;
        });
    }
}
