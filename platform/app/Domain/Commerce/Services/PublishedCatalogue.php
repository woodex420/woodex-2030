<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Services;

use App\Domain\Commerce\Models\Category;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\ProductMedia;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * What the shop may read of the products (master list T-105; the rules are docs/woodex-os/29-API-V1-RULES.md).
 *
 * The one rule this class exists to keep: a product is handed out only as the copy that was **published**, never
 * as the draft. An editor may keep working on a draft for days after a product went live, and what the shop shows
 * must not follow it until somebody publishes again. So every field comes from the published revision's own
 * content, and nothing here reads `commerce_products.draft`.
 *
 * Three states are invisible to the shop, and each is invisible the same way — as if the product were not there:
 *   - a product that was never published (no published revision);
 *   - a product whose publication was withdrawn (the revision was dropped; see ProductCatalogue::withdraw);
 *   - a product that is archived.
 *
 * Money: the editor keeps prices as text in rupees ("1200.50"), which is right for a form and wrong for a
 * machine. Here each is turned into whole **paisa**, and the currency stands beside it (§7 of the rules).
 * A price that was never filled in is not a price of zero: such a product is "on request" and carries no figure
 * at all.
 *
 * A moment: `updated_at` of the product (the last time anybody saved it) and `published_at` of the revision that
 * was published, both in UTC to the second.
 */
final class PublishedCatalogue
{
    /** The keys of a published revision's content that the shop is handed, and no others. */
    private const array HANDED_OUT = [
        'name', 'slug', 'short_description', 'description', 'shipping', 'warranty',
        'price', 'category_ids', 'primary_category_id', 'series_ids', 'availability', 'variants',
        'specifications', 'features', 'faqs', 'seo_title', 'seo_description', 'og_title', 'og_description',
        'sale_price', 'sale_start', 'sale_end', 'is_hot', 'is_featured', 'related_mode', 'related_ids', 'images',
    ];

    public function __construct(private readonly ProductMerchandising $merchandising) {}

    /**
     * A page of the products the shop may show, newest change first — so that a client that keeps the highest
     * `updated_at` it has seen, and asks for what changed after it, receives every change and no other.
     *
     * @param  array{category?: string|null, series?: string|null, since?: string|null}  $filter
     * @return array{items: list<array<string, mixed>>, total: int}|null null when the filter names a slug that
     *                                                                   nothing has; the address answers 404 then
     */
    public function page(array $filter, int $perPage): ?array
    {
        // Joined to the revision that was published: every filter below reads what that revision holds, so a
        // draft saved after publication cannot put a product in a category it was never published in.
        $query = Product::query()
            ->join('commerce_product_revisions as r', 'r.id', '=', 'commerce_products.published_revision_id')
            ->whereColumn('r.product_id', 'commerce_products.id')
            ->whereNull('commerce_products.archived_at')
            ->select('commerce_products.*');

        if (filled($filter['category'] ?? null)) {
            $category = Category::query()->where('slug', $filter['category'])->where('is_active', true)->first();
            if ($category === null) {
                return null;
            }
            $ids = $this->withDescendants($category);
            $query->where(function ($where) use ($ids): void {
                // A product's categories are in the published revision's content, so the match is made there.
                foreach ($ids as $id) {
                    $where->orWhereRaw($this->revisionContains('category_ids', $id));
                }
            });
        }

        if (filled($filter['series'] ?? null)) {
            $series = DB::table('commerce_series')->where('slug', $filter['series'])->where('is_active', 1)->value('id');
            if ($series === null) {
                return null;
            }
            $query->whereRaw($this->revisionContains('series_ids', (int) $series));
        }

        if (filled($filter['since'] ?? null)) {
            $query->where('commerce_products.updated_at', '>=', CarbonImmutable::parse((string) $filter['since'], 'UTC'));
        }

        $total = (clone $query)->count();
        $products = $query->orderByDesc('commerce_products.updated_at')->orderByDesc('commerce_products.id')->forPage(max(1, request()->integer('page', 1)), $perPage)->get();

        return ['items' => $this->shape($products), 'total' => $total];
    }

    /**
     * One product by its slug, as the published copy, or null when the shop may not see it.
     *
     * @return array<string, mixed>|null
     */
    public function one(string $slug): ?array
    {
        $product = Product::query()->where('commerce_products.slug', $slug)->whereNotNull('commerce_products.published_revision_id')->first();

        // An address is read exactly as it was written, on every database. MySQL and MariaDB compare a text
        // column without regard to case by default (utf8mb4_unicode_ci), SQLite compares it with regard to case,
        // and the collation can be changed under either. So the database finds the row loosely, and the answer is
        // refused here when the slug is not the one that was published: /Executive-Desk is not the address of
        // /executive-desk, and a shop that linked one must not be handed the other's page under a second address.
        return $product === null || $product->slug !== $slug ? null : ($this->shape([$product])[0] ?? null);
    }

    /**
     * The bytes of one published photograph of a product, or null.
     *
     * @return array{path: string, mime: string}|null
     */
    public function pictureFile(string $slug, int $image, int $version): ?array
    {
        $product = Product::query()->where('slug', $slug)->whereNotNull('published_revision_id')->first();
        // The same exactness as one(): a database that compares slugs without regard to case must not serve a
        // picture under an address the product was not published at.
        if ($product === null || $product->slug !== $slug) {
            return null;
        }
        $revision = DB::table('commerce_product_revisions')->where('id', $product->published_revision_id)->where('product_id', $product->id)->first();
        $content = $revision === null ? [] : json_decode((string) $revision->content, true);
        $ids = array_map(static fn (mixed $image): int => (int) (is_array($image) ? ($image['id'] ?? 0) : 0), (array) (is_array($content) ? ($content['images'] ?? []) : []));
        if (! in_array($image, $ids, true)) {
            return null;
        }
        $media = ProductMedia::where('product_id', $product->id)->find($image);
        $source = $media === null ? null : DB::table('master_media_sources')->where('source_type', 'product_media')->where('source_id', $media->id)->value('version_id');
        if ((int) $source !== $version) {
            return null;
        }
        $row = DB::table('master_media_versions')->where('id', $version)->first();
        if ($row === null || preg_match('~^media/masters/[a-f0-9]{64}\.(jpg|png|webp)$~', (string) $row->path) !== 1 || ! Storage::disk('local')->exists((string) $row->path)) {
            return null;
        }

        return ['path' => Storage::disk('local')->path((string) $row->path), 'mime' => (string) $row->mime];
    }

    /**
     * The products as the contract names them, each field from the published revision.
     *
     * @param  iterable<Product>  $products
     * @return list<array<string, mixed>>
     */
    private function shape(iterable $products): array
    {
        $products = collect($products);
        $revisions = DB::table('commerce_product_revisions')->whereIn('id', $products->pluck('published_revision_id')->all())->get()->keyBy('id');
        // The published version of each picture's bytes, kept in the media registry under the picture's own number.
        $versions = DB::table('master_media_sources')->where('source_type', 'product_media')
            ->whereIn('source_id', ProductMedia::whereIn('product_id', $products->pluck('id')->all())->pluck('id')->all())
            ->pluck('version_id', 'source_id');

        $shaped = [];
        $number = 0;
        foreach ($products as $product) {
            $number++;
            $revision = $revisions->get($product->published_revision_id);
            if ($revision === null || (int) $revision->product_id !== $product->id || $product->archived_at !== null) {
                continue;
            }
            $content = json_decode((string) $revision->content, true);
            if (! is_array($content)) {
                continue;
            }
            $content = array_intersect_key($content, array_flip(self::HANDED_OUT));

            // A published product always has a base price: publication refuses one without (ProductCatalogue::
            // publish). So the figure below is always a number; what the shop does with it is decided by
            // `availability`, which is where the editor says "ask us" instead of "this is the price".
            $salePrice = $this->money($content['sale_price'] ?? '');
            $saleOn = $salePrice !== null && $this->merchandising->isOnSale($content, CarbonImmutable::now('UTC'));
            $price = ($saleOn ? $salePrice : $this->money($content['price'] ?? '')) ?? 0;
            $availability = (string) ($content['availability'] ?? 'on_request');

            $variants = [];
            foreach ((array) ($content['variants'] ?? []) as $variant) {
                if (! is_array($variant) || ! filled($variant['sku'] ?? null)) {
                    continue;
                }
                $variantSale = $this->money($variant['sale_price'] ?? '');
                $variantOn = $variantSale !== null && $this->merchandising->isOnSale($variant, CarbonImmutable::now('UTC'));
                $variantPrice = $variantOn ? $variantSale : $this->money($variant['price'] ?? '');
                $variants[] = [
                    'sku' => (string) $variant['sku'],
                    'colour' => (string) ($variant['colour'] ?? ''),
                    'material' => (string) ($variant['material'] ?? ''),
                    'size' => (string) ($variant['size'] ?? ''),
                    'price_minor' => $variantPrice,
                    'availability' => (string) ($variant['availability'] ?? 'on_request'),
                ];
            }

            $shaped[] = [
                'id' => (string) $product->slug,
                'name' => (string) ($content['name'] ?? $product->name),
                'slug' => (string) $product->slug,
                'short_description' => (string) ($content['short_description'] ?? ''),
                'description' => (string) ($content['description'] ?? ''),
                'shipping' => (string) ($content['shipping'] ?? ''),
                'warranty' => (string) ($content['warranty'] ?? ''),
                'category_ids' => array_values(array_map('intval', (array) ($content['category_ids'] ?? []))),
                'primary_category_id' => filled($content['primary_category_id'] ?? null) ? (int) $content['primary_category_id'] : null,
                'series_ids' => array_values(array_map('intval', (array) ($content['series_ids'] ?? []))),
                'availability' => $availability,
                'price_minor' => $price,
                'was_price_minor' => $saleOn ? $this->money($content['price'] ?? '') : null,
                'currency' => 'PKR',
                // "Ask us": the editor did not give a price to buy at. The figure above is not an offer then, and
                // the shop must not print it as one (the storefront calls this priceOnRequest).
                'on_request' => $availability === 'on_request',
                'variants' => $variants,
                'specifications' => $this->rows((array) ($content['specifications'] ?? []), ['label', 'value']),
                'features' => array_values(array_map('strval', (array) ($content['features'] ?? []))),
                'faqs' => $this->rows((array) ($content['faqs'] ?? []), ['question', 'answer']),
                'seo_title' => (string) ($content['seo_title'] ?? ''),
                'seo_description' => (string) ($content['seo_description'] ?? ''),
                'og_title' => (string) ($content['og_title'] ?? ''),
                'og_description' => (string) ($content['og_description'] ?? ''),
                'is_hot' => (bool) ($content['is_hot'] ?? false),
                'is_featured' => (bool) ($content['is_featured'] ?? false),
                'related_mode' => (string) ($content['related_mode'] ?? 'category'),
                'related_ids' => array_values(array_map('intval', (array) ($content['related_ids'] ?? []))),
                'pictures' => $this->pictures($product, (array) ($content['images'] ?? []), $versions),
                'position' => $number,
                'revision' => (int) $revision->id,
                'published_at' => $this->time($revision->created_at),
                'updated_at' => $this->time($product->updated_at),
            ];
        }

        return $shaped;
    }

    /**
     * The photographs of one published product, in the order its editor put them, each at an address of its own.
     *
     * The address carries the number of the version of the bytes, so that a photograph replaced by a later
     * publication has a different address and no client keeps showing the old one.
     *
     * @param  list<mixed>  $images
     * @param  Collection<int|string, mixed>  $versions  the version of each picture, by its number
     * @return list<array{url: string, alt: string}>
     */
    private function pictures(Product $product, array $images, $versions): array
    {
        $pictures = [];
        foreach ($images as $image) {
            $id = (int) (is_array($image) ? ($image['id'] ?? 0) : 0);
            $version = (int) ($versions[$id] ?? 0);
            if ($id < 1 || $version < 1) {
                continue;
            }
            $pictures[] = [
                'url' => '/api/v1/catalogue/products/'.$product->slug.'/picture/'.$id.'/'.$version,
                'alt' => (string) (is_array($image) ? ($image['alt'] ?? '') : ''),
            ];
        }

        return $pictures;
    }

    /**
     * Rupees as text, as the editor keeps them ("1200.50"), to whole paisa. A blank price is null — not zero: a
     * product with no price is one to ask about, and printing 0 would put a free product in the shop.
     */
    private function money(mixed $value): ?int
    {
        $text = trim((string) (is_scalar($value) ? $value : ''));
        if (preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $text, $parts) !== 1) {
            return null;
        }

        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    /** A moment as every answer writes it: UTC, to the second. */
    private function time(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value, 'UTC')->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Rows of a fixed shape, keeping only the keys named, each as text — so that a field the editor adds later is
     * not handed out by accident.
     *
     * @param  array<int, mixed>  $rows
     * @param  list<string>  $keys
     * @return list<array<string, string>>
     */
    private function rows(array $rows, array $keys): array
    {
        $kept = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $kept[] = array_combine($keys, array_map(static fn (string $key): string => (string) ($row[$key] ?? ''), $keys));
        }

        return $kept;
    }

    /**
     * A category and every category below it: a product in a subcategory is shown under its parent too, which is
     * how the shop's menu works.
     *
     * @return list<int>
     */
    private function withDescendants(Category $category): array
    {
        $all = Category::query()->where('is_active', true)->get(['id', 'parent_id']);
        $ids = [$category->id];
        $seen = [];
        do {
            $added = 0;
            foreach ($all as $row) {
                if (in_array($row->id, $ids, true) || ! in_array((int) $row->parent_id, $ids, true) || isset($seen[$row->id])) {
                    continue;
                }
                $ids[] = (int) $row->id;
                $seen[$row->id] = true;
                $added++;
            }
        } while ($added > 0);

        return $ids;
    }

    /**
     * A condition on the published revision's content: does the list at $key hold $value? Written with the
     * database's own JSON functions for the three engines the suite runs on (SQLite, MySQL, PostgreSQL).
     */
    private function revisionContains(string $key, int $value): string
    {
        $driver = DB::connection()->getDriverName();

        // The revision is joined as r in each query that uses this.
        $column = match ($driver) {
            'sqlite' => "json_each(r.content, '$.{$key}') AS je",
            'pgsql' => "json_array_elements((r.content::jsonb) -> '{$key}')",
            default => "JSON_TABLE(r.content, '$.{$key}[*]' COLUMNS (value INT PATH '$')) AS jt",
        };

        return match ($driver) {
            'sqlite' => "EXISTS (SELECT 1 FROM {$column} WHERE CAST(je.value AS INTEGER) = {$value})",
            'pgsql' => "EXISTS (SELECT 1 FROM {$column} AS je WHERE (je.value #>> '{}')::int = {$value})",
            default => "EXISTS (SELECT 1 FROM {$column} WHERE jt.value = {$value})",
        };
    }
}
