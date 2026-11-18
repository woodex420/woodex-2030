<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Services;

use App\Domain\Commerce\Models\Category;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\ProductMedia;
use App\Domain\Commerce\Models\Series;
use App\Domain\Media\Services\MasterMedia;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ProductCatalogue
{
    public function authorize(User $user, bool $publish = false): void
    {
        abort_unless($user->can('commerce.catalog.view') && $user->can('commerce.catalog.manage') && (! $publish || $user->can('commerce.catalog.publish')), 403);
    }

    public function defaults(): array
    {
        return ['name' => '', 'slug' => '', 'short_description' => '', 'description' => '', 'shipping' => '', 'warranty' => '',
            'category_ids' => [], 'primary_category_id' => '', 'series_ids' => [], 'price' => '', 'availability' => 'on_request',
            'variants' => [], 'images' => [], 'specifications' => [], 'features' => [], 'faqs' => [],
            'seo_title' => '', 'seo_description' => '', 'og_title' => '', 'og_description' => '',
            'sale_price' => '', 'sale_start' => '', 'sale_end' => '', 'is_hot' => false, 'is_featured' => false, 'featured_position' => 0,
            'related_mode' => 'category', 'related_ids' => [], 'reviewed' => false];
    }

    public function validate(array $data, ?Product $product = null): array
    {
        $data = array_replace($this->defaults(), $data);
        $money = ['nullable', 'string', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/'];
        $valid = Validator::make($data, [
            'name' => ['required', 'string', 'max:160'], 'slug' => ['required', 'string', 'max:140', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('commerce_products', 'slug')->ignore($product?->id)],
            'short_description' => ['nullable', 'string', 'max:1000'], 'description' => ['nullable', 'string', 'max:20000'],
            'shipping' => ['nullable', 'string', 'max:5000'], 'warranty' => ['nullable', 'string', 'max:5000'],
            'category_ids' => ['array', 'max:30'], 'category_ids.*' => ['integer', 'distinct', Rule::exists('commerce_categories', 'id')],
            'primary_category_id' => ['nullable', 'integer', Rule::in($data['category_ids'] ?? [])],
            'series_ids' => ['array', 'max:20'], 'series_ids.*' => ['integer', 'distinct', Rule::exists('commerce_series', 'id')],
            'price' => $money, 'availability' => ['required', Rule::in(['available', 'unavailable', 'on_request'])],
            'variants' => ['array', 'max:100'], 'variants.*.sku' => ['required', 'string', 'max:100', 'distinct'],
            'variants.*.colour' => ['nullable', 'string', 'max:100'], 'variants.*.material' => ['nullable', 'string', 'max:150'], 'variants.*.size' => ['nullable', 'string', 'max:150'],
            'variants.*.price' => $money, 'variants.*.sale_price' => $money, 'variants.*.sale_start' => ['nullable', 'date_format:Y-m-d\TH:i'], 'variants.*.sale_end' => ['nullable', 'date_format:Y-m-d\TH:i'], 'variants.*.availability' => ['required', Rule::in(['available', 'unavailable', 'on_request'])],
            'images' => ['array', 'max:24'], 'images.*.id' => ['required', 'integer', 'distinct', Rule::exists('commerce_product_media', 'id')->where('product_id', $product?->id ?? 0)],
            'images.*.alt' => ['nullable', 'string', 'max:200'],
            'specifications' => ['array', 'max:60'], 'specifications.*.label' => ['required', 'string', 'max:150'], 'specifications.*.value' => ['required', 'string', 'max:1500'],
            'features' => ['array', 'max:60'], 'features.*' => ['required', 'string', 'max:1000'],
            'faqs' => ['array', 'max:30'], 'faqs.*.question' => ['required', 'string', 'max:300'], 'faqs.*.answer' => ['required', 'string', 'max:3000'],
            'seo_title' => ['nullable', 'string', 'max:160'], 'seo_description' => ['nullable', 'string', 'max:320'],
            'og_title' => ['nullable', 'string', 'max:160'], 'og_description' => ['nullable', 'string', 'max:320'],
            'related_mode' => ['required', Rule::in(['off', 'manual', 'category'])], 'related_ids' => ['array', 'max:12'],
            'related_ids.*' => ['integer', 'distinct', Rule::exists('commerce_products', 'id'), Rule::notIn([$product?->id])],
            'reviewed' => ['required', 'boolean'],
        ])->validate();
        $merchandising = app(ProductMerchandising::class)->validate($data);
        foreach (['variants' => ['sku', 'colour', 'material', 'size', 'price', 'availability', 'sale_price', 'sale_start', 'sale_end'], 'images' => ['id', 'alt'], 'specifications' => ['label', 'value'], 'faqs' => ['question', 'answer']] as $key => $keys) {
            $valid[$key] = array_values(array_map(fn ($row) => array_intersect_key($row, array_flip($keys)), $valid[$key]));
        }
        foreach (['category_ids', 'series_ids', 'related_ids'] as $key) {
            $valid[$key] = array_values(array_map('intval', $valid[$key]));
        }
        $valid['primary_category_id'] = filled($valid['primary_category_id']) ? (int) $valid['primary_category_id'] : null;
        $valid['reviewed'] = (bool) $valid['reviewed'];

        return array_replace($valid, array_intersect_key($merchandising, array_flip(['sale_price', 'sale_start', 'sale_end', 'is_hot', 'is_featured', 'featured_position'])));
    }

    public function save(?Product $product, array $data, int $version, User $user): Product
    {
        $this->authorize($user);

        return DB::transaction(function () use ($product, $data, $version, $user) {
            $locked = $product ? Product::lockForUpdate()->findOrFail($product->id) : new Product;
            if ($locked->exists && $locked->version !== $version) {
                throw ValidationException::withMessages(['conflict' => 'Another editor changed this product. Reload before saving.']);
            }
            abort_if($locked->archived_at, 409, 'Restore this archived product before editing.');
            $valid = $this->validate($data, $locked->exists ? $locked : null);
            $locked->forceFill(['name' => $valid['name'], 'slug' => $valid['slug'], 'draft' => $valid, 'version' => $version + 1])->save();
            $revisionId = DB::table('commerce_product_revisions')->insertGetId(['product_id' => $locked->id, 'content' => json_encode($valid, JSON_THROW_ON_ERROR), 'created_by' => $user->id, 'created_at' => now()]);
            app(MasterMedia::class)->recordRevision($locked->id, $revisionId, $valid['images']);
            activity('commerce')->performedOn($locked)->causedBy($user)->log('Product draft saved; storefront unchanged');

            return $locked;
        });
    }

    public function fingerprint(Product $product): string
    {
        return hash('sha256', json_encode(['version' => $product->version, 'content' => $product->draft], JSON_THROW_ON_ERROR));
    }

    public function publish(Product $product, int $version, User $user): void
    {
        $this->authorize($user, true);
        DB::transaction(function () use ($product, $version, $user): void {
            $locked = Product::lockForUpdate()->findOrFail($product->id);
            if ($locked->version !== $version) {
                throw ValidationException::withMessages(['conflict' => 'The product changed; reload and preview it again.']);
            }
            $data = $this->validate($locked->draft, $locked);
            $errors = [];
            if ($locked->archived_at || ! $data['reviewed']) {
                $errors[] = 'Review and approve product identity, photos, prices, variants and policy claims before publication.';
            }
            if (! $data['category_ids'] || ! $data['primary_category_id']) {
                $errors[] = 'Choose a primary category and at least one category.';
            }
            foreach ($data['category_ids'] as $id) {
                $category = Category::find($id);
                $seen = [];
                while ($category) {
                    if (! $category->is_active || isset($seen[$category->id])) {
                        $errors[] = 'All selected categories and their ancestors must be active.';
                        break;
                    }
                    $seen[$category->id] = true;
                    $category = $category->parent_id ? Category::find($category->parent_id) : null;
                }
            }
            if (Series::whereIn('id', $data['series_ids'])->where('is_active', false)->exists()) {
                $errors[] = 'Selected Series must be active.';
            }
            if (! filled($data['price']) || ! filled($data['description']) || ! filled($data['shipping']) || ! filled($data['warranty'])) {
                $errors[] = 'Base price, description, shipping and warranty must be reviewed and completed.';
            }
            if (! $data['images']) {
                $errors[] = 'Add a primary product image.';
            }
            foreach ($data['images'] as $image) {
                $media = ProductMedia::where('product_id', $locked->id)->find($image['id']);
                if (! filled($image['alt']) || ! $media || ! Storage::disk('local')->exists($media->path)) {
                    $errors[] = 'Every image needs alternative text and an available file.';
                }
            }
            foreach ($data['variants'] as $variant) {
                if (! filled($variant['price'])) {
                    $errors[] = 'Each variant combination needs its own explicit price.';
                }
            }
            $receipt = DB::table('commerce_product_previews')->where('product_id', $locked->id)->where('user_id', $user->id)->first();
            if (! $receipt || (int) $receipt->version !== $locked->version || ! hash_equals($receipt->fingerprint, $this->fingerprint($locked))) {
                $errors[] = 'Open the exact saved preview under your own account before publication.';
            }
            if ($errors) {
                throw ValidationException::withMessages(['publish' => array_unique($errors)]);
            }
            $revision = DB::table('commerce_product_revisions')->where('product_id', $locked->id)->latest('id')->first();
            $locked->forceFill(['published_revision_id' => $revision->id, 'version' => $version + 1])->save();
            activity('commerce')->performedOn($locked)->causedBy($user)->log('Reviewed native product snapshot published');
        });
    }

    public function withdraw(Product $product, User $user, bool $archive = false): void
    {
        $this->authorize($user, true);
        DB::transaction(function () use ($product, $user, $archive): void {
            $locked = Product::lockForUpdate()->findOrFail($product->id);
            $locked->forceFill(['published_revision_id' => null, 'archived_at' => $archive ? now() : $locked->archived_at, 'version' => $locked->version + 1])->save();
            activity('commerce')->performedOn($locked)->causedBy($user)->log($archive ? 'Product archived; media and revisions retained' : 'Product publication withdrawn');
        });
    }
}
