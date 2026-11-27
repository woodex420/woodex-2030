<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Services;

use App\Domain\Commerce\Actions\InstallCatalogueTaxonomy;
use App\Domain\Commerce\Models\Category;
use App\Domain\Commerce\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The real catalogue: the 170 products of woodexfurniture.pk as captured on 16 November 2026 and passed through
 * the rules of catalog/rules/ (docs/furniture-2030/catalogue/IMPORT-REPORT.md). The owner released it for the
 * admin on 17 November 2026 (decision log DL-022).
 *
 * Everything is loaded as a draft. A draft is seen by staff and by no customer; a product goes on sale when a
 * member of staff publishes it. Nothing is overwritten: a product whose address is already in the admin is left
 * as it is, so the import can be run again after the catalogue file changes.
 *
 * What the capture does not hold is not invented: no stock (every product is "confirm on request"), no
 * description, no options. The photograph of each product is still on the live shop; its address is kept with
 * the product, and the file is copied in by the media import, which needs network access to that shop.
 */
final class WoodexCatalogue
{
    public function __construct(private ProductCatalogue $catalogue) {}

    /** @return array{schema: int, sha256: string, dataset: string, site: string, capturedOn: string, counts: array<string, int>, categories: list<array{slug: string, name: string, parent: string}>, items: list<array<string, mixed>>} */
    public function source(): array
    {
        return json_decode((string) file_get_contents(resource_path('data/woodex-catalogue.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array{total: int, new: int, on_request: int, to_check: int, site: string, captured_on: string, dataset: string, sha256: string, categories: list<string>} */
    public function summary(): array
    {
        $source = $this->source();
        $ids = array_column($source['items'], 'id');
        $taken = array_flip($this->taken($ids));

        return [
            'total' => count($ids),
            'new' => count(array_filter($ids, static fn (string $id): bool => ! isset($taken[$id]))),
            'on_request' => count(array_filter($source['items'], static fn (array $row): bool => (bool) $row['priceOnRequest'])),
            'to_check' => count(array_filter($source['items'], static fn (array $row): bool => ($row['review'] ?? []) !== [])),
            'site' => $source['site'],
            'captured_on' => $source['capturedOn'],
            'dataset' => $source['dataset'],
            'sha256' => $source['sha256'],
            'categories' => array_values(array_map(
                static fn (array $category): string => $category['name'],
                array_filter($source['categories'], static fn (array $category): bool => ! Category::query()->where('slug', $category['slug'])->exists()),
            )),
        ];
    }

    /** Is the real catalogue in this database? Asked by the notice a preview shows on every screen. */
    public static function loaded(): bool
    {
        return Product::query()->where('source_record->source->site', 'woodexfurniture.pk')->exists();
    }

    /** @return array{products: int, categories: int} what this run added */
    public function import(User $user): array
    {
        $this->catalogue->authorize($user);

        return DB::transaction(function () use ($user): array {
            $source = $this->source();
            $categories = $this->installCategories($source['categories']);
            $aliases = json_decode((string) file_get_contents(resource_path('data/commerce-taxonomy.json')), true, flags: JSON_THROW_ON_ERROR)['aliases'];
            $categoryIds = Category::query()->pluck('id', 'slug')->all();
            $taken = array_flip($this->taken(array_column($source['items'], 'id')));
            $products = 0;

            foreach ($source['items'] as $row) {
                if (isset($taken[$row['id']])) {
                    continue;
                }

                // The live shop shows one price, or a price and the price it was reduced from. A price of zero
                // is "on request" (rule R-3): the product has no price, and a customer asks for a quotation.
                $onRequest = (bool) $row['priceOnRequest'];
                $was = (int) ($row['originalPrice'] ?? 0);
                $reduced = ! $onRequest && $was > (int) $row['price'];
                $regular = $onRequest ? '' : (string) ($reduced ? $was : (int) $row['price']);
                $sale = $reduced ? (string) (int) $row['price'] : '';

                $assigned = [];
                foreach (array_filter([$row['category'], $row['subcategory'] ?? null]) as $slug) {
                    $assigned[] = $categoryIds[$aliases[$slug] ?? $slug];
                }

                $data = $this->catalogue->defaults();
                $data['name'] = $row['name'];
                $data['slug'] = $row['id'];
                $data['price'] = $regular;
                // Rule R-3 in the admin's own word: the product is published without a figure (decision DL-049).
                $data['price_on_request'] = $onRequest;
                $data['sale_price'] = $sale;
                // No stock figure is in the capture, and none is assumed.
                $data['availability'] = 'on_request';
                $data['category_ids'] = $assigned;
                $data['primary_category_id'] = end($assigned) ?: '';
                // One row carries the product's code and nothing else; the price stays on the product. The shop is
                // handed that code as the product's own, not as an option to choose (ProductCatalogue::ownCodeRow).
                $data['variants'] = [['sku' => $row['sku'], 'colour' => '', 'material' => '', 'size' => '', 'price' => '', 'availability' => 'on_request', 'sale_price' => '', 'sale_start' => '', 'sale_end' => '']];
                $data['seo_title'] = $row['name'];

                $product = $this->catalogue->save(null, $data, 0, $user);
                $product->forceFill([
                    'source_key' => $row['id'],
                    'source_record' => $row,
                    'source_hash' => $source['sha256'],
                    'review_issues' => array_values(array_map(static fn (array $point): string => (string) $point['text'], $row['review'] ?? [])) ?: null,
                ])->save();
                $products++;
            }

            activity('commerce')->causedBy($user)
                ->withProperties(['products' => $products, 'categories' => $categories, 'dataset' => $source['dataset'], 'source_hash' => $source['sha256']])
                ->log('Woodex catalogue imported as drafts; nothing published or overwritten');

            return ['products' => $products, 'categories' => $categories];
        });
    }

    /**
     * The categories the live shop sells under and the taxonomy does not have. Each is created switched off,
     * under its parent, after the categories already there: the owner switches a category on.
     *
     * @param  list<array{slug: string, name: string, parent: string}>  $categories
     */
    private function installCategories(array $categories): int
    {
        app(InstallCatalogueTaxonomy::class)->handle(); // adds what is missing only

        $added = 0;
        foreach ($categories as $category) {
            if (Category::query()->where('slug', $category['slug'])->exists()) {
                continue;
            }
            $parent = Category::query()->where('slug', $category['parent'])->firstOrFail();
            Category::query()->create([
                'slug' => $category['slug'],
                'name' => $category['name'],
                'parent_id' => $parent->id,
                'position' => (int) Category::query()->where('parent_id', $parent->id)->max('position') + 1,
                'is_active' => false,
            ]);
            $added++;
        }

        return $added;
    }

    /**
     * @param  list<string>  $ids
     * @return list<string> the addresses of the catalogue that a product of the admin already has
     */
    private function taken(array $ids): array
    {
        $existing = Product::query()->whereIn('source_key', $ids)->orWhereIn('slug', $ids)->get(['source_key', 'slug']);

        return array_values(array_unique(array_filter([...$existing->pluck('source_key')->all(), ...$existing->pluck('slug')->all()])));
    }
}
