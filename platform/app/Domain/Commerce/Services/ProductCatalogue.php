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

    /**
     * How a product can be ordered, in the words a screen prints. The stored key is never shown: "on_request"
     * above a price was read as "price on request", and it means "made to order" (decision DL-049).
     */
    public static function availabilityLabel(?string $key): string
    {
        return ['available' => 'Available', 'unavailable' => 'Unavailable', 'on_request' => 'Made to order'][$key ?? ''] ?? 'Availability not chosen';
    }

    /**
     * What a draft costs, as a screen prints it: the figure with its thousands, "Price on request" on the word of
     * its editor, or that no price is set. Never a figure that nobody typed.
     *
     * @param  array<string, mixed>  $draft
     */
    public static function priceLabel(array $draft): string
    {
        $price = $draft['price'] ?? null;

        if (filled($price)) {
            return 'PKR '.(is_numeric($price) ? number_format((float) $price, floor((float) $price) == (float) $price ? 0 : 2) : $price);
        }

        return ($draft['price_on_request'] ?? false) ? 'Price on request' : 'Price not set';
    }

    public function defaults(): array
    {
        return ['name' => '', 'slug' => '', 'short_description' => '', 'description' => '', 'shipping' => '', 'warranty' => '',
            'category_ids' => [], 'primary_category_id' => '', 'series_ids' => [], 'price' => '', 'price_on_request' => false, 'availability' => 'on_request',
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
            'price' => $money, 'price_on_request' => ['required', 'boolean'], 'availability' => ['required', Rule::in(['available', 'unavailable', 'on_request'])],
            'variants' => ['array', 'max:100'], 'variants.*.sku' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
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
        $valid['price_on_request'] = (bool) $valid['price_on_request'];
        $valid = array_replace($valid, array_intersect_key($merchandising, array_flip(['sale_price', 'sale_start', 'sale_end', 'is_hot', 'is_featured', 'featured_position'])));

        $this->refuseAPriceBesideOnRequest($valid);
        $this->refuseACodeOfAnotherProduct($valid, $product);

        return $valid;
    }

    /**
     * "Price on request" is the editor's own word that a product is sold by quotation (decision DL-049): the shop
     * then prints no figure. A figure left beside that word would be a price nobody can say is offered or not, so
     * the two are not saved together.
     */
    private function refuseAPriceBesideOnRequest(array $valid): void
    {
        if (! $valid['price_on_request']) {
            return;
        }
        $priced = filled($valid['price']) || filled($valid['sale_price'] ?? '');
        foreach ($valid['variants'] as $variant) {
            $priced = $priced || filled($variant['price'] ?? '') || filled($variant['sale_price'] ?? '');
        }
        if ($priced) {
            throw ValidationException::withMessages(['price_on_request' => 'A product priced on request carries no price. Clear its prices, or untick “Price on request”.']);
        }
    }

    /**
     * One code names one thing (decision DL-049). A code is compared without regard to case or to the spaces
     * around it, and against every other product, archived or not: an order, a quotation and the workshop all
     * find a product by its code.
     *
     * Held here, at every save and again at publication, and not by an index: the codes live inside the draft.
     * Two editors who give two products the same new code in the same moment can both pass; the second
     * publication is then refused.
     */
    private function refuseACodeOfAnotherProduct(array $valid, ?Product $product): void
    {
        $mine = [];
        foreach ($valid['variants'] as $index => $variant) {
            $mine[self::codeKey($variant['sku'] ?? '')] = $index;
        }
        unset($mine['']);
        if ($mine === []) {
            return;
        }
        $messages = [];
        $others = DB::table('commerce_products')->when($product?->exists, fn ($query) => $query->where('id', '!=', $product->id))->orderBy('id')->get(['name', 'draft']);
        foreach ($others as $other) {
            $draft = json_decode((string) $other->draft, true);
            foreach ((array) (is_array($draft) ? ($draft['variants'] ?? []) : []) as $variant) {
                $key = self::codeKey(is_array($variant) ? ($variant['sku'] ?? '') : '');
                if ($key !== '' && isset($mine[$key]) && ! isset($messages['variants.'.$mine[$key].'.sku'])) {
                    $messages['variants.'.$mine[$key].'.sku'] = 'The code '.$valid['variants'][$mine[$key]]['sku'].' is already the code of “'.$other->name.'”. One code names one thing.';
                }
            }
        }
        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    /** A code as it is compared: without the spaces around it, in capitals. */
    public static function codeKey(mixed $code): string
    {
        return mb_strtoupper(trim(is_scalar($code) ? (string) $code : ''));
    }

    /**
     * The row that carries the product's own code, or null.
     *
     * A product sold in one form has one row under "Product code and options" with a code and nothing else: no
     * colour, no material, no size. That row is not a choice a buyer makes: it is the product's code, and the
     * import of the real catalogue writes every product this way (WoodexCatalogue). A product sold in several
     * forms has a row for each, and each is a choice.
     *
     * @param  array<int, mixed>  $variants
     * @return array<string, mixed>|null
     */
    public static function ownCodeRow(array $variants): ?array
    {
        $rows = array_values(array_filter($variants, 'is_array'));
        if (count($rows) !== 1) {
            return null;
        }
        foreach (['colour', 'material', 'size'] as $key) {
            if (filled($rows[0][$key] ?? '')) {
                return null;
            }
        }

        return filled($rows[0]['sku'] ?? '') ? $rows[0] : null;
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
            // A price, or the editor's own word that the product is sold by quotation: never neither, and never a
            // figure made up so that the product can go out (decision DL-049).
            if (! filled($data['price']) && ! $data['price_on_request']) {
                $errors[] = 'Give a base price, or tick “Price on request”.';
            }
            if (! filled($data['description']) || ! filled($data['shipping']) || ! filled($data['warranty'])) {
                $errors[] = 'Description, shipping and warranty must be reviewed and completed.';
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
            // An option without a price of its own is sold at the product's price: the rule a quotation line
            // already follows (App\Domain\Sales\Services\CatalogueProducts::choices). Until 27 November 2026 each
            // option had to repeat the price, which no product of the real catalogue did: its one row carries the
            // product's code and nothing else, so none of the 170 could be published as imported.
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
