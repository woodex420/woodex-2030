<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Commerce;

use App\Domain\Commerce\Models\Category;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\Series;
use App\Domain\Commerce\Services\ProductCatalogue;
use App\Domain\Commerce\Services\ProductTransfer;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts::admin')]
final class Products extends Component
{
    use InteractsWithAdmin, WithPagination;

    #[Locked]
    public ?int $quickProductId = null;

    #[Locked]
    public int $quickVersion = 0;

    public bool $showQuickEdit = false;

    public array $quick = [];

    public function openQuickEdit(int $id): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
        $product = Product::whereNull('archived_at')->findOrFail($id);
        $this->quickProductId = $id;
        $this->quickVersion = $product->version;
        $this->quick = array_intersect_key(array_replace(app(ProductCatalogue::class)->defaults(), $product->draft), array_flip(['name', 'price', 'availability', 'category_ids', 'primary_category_id', 'series_ids', 'sale_price', 'sale_start', 'sale_end', 'is_hot', 'is_featured', 'featured_position']));
        $this->showQuickEdit = true;
        $this->resetErrorBag();
    }

    public function saveQuickEdit(): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
        $product = Product::findOrFail($this->quickProductId);
        $allowed = array_flip(['name', 'price', 'availability', 'category_ids', 'primary_category_id', 'series_ids', 'sale_price', 'sale_start', 'sale_end', 'is_hot', 'is_featured', 'featured_position']);
        $data = array_replace($product->draft, array_intersect_key($this->quick, $allowed));
        $data['reviewed'] = false;
        app(ProductCatalogue::class)->save($product, $data, $this->quickVersion, $this->user());
        $this->showQuickEdit = false;
        $this->toast('Quick changes saved to draft. Review, preview and explicitly publish/update; storefront unchanged.');
    }

    public string $search = '';

    public string $status = '';

    public string $categoryFilter = '';

    public string $seriesFilter = '';

    public function updatedCategoryFilter(): void
    {
        $this->updatedSearch();
    }

    public function updatedSeriesFilter(): void
    {
        $this->updatedSearch();
    }

    public array $selected = [];

    public bool $confirmBulk = false;

    public string $bulkCategory = '';

    public string $bulkSeries = '';

    public string $bulkAvailability = '';

    public function mount(): void
    {
        abort_unless($this->user()->can('commerce.catalog.view'), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->selected = [];
        $this->confirmBulk = false;
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
        $this->selected = [];
        $this->confirmBulk = false;
    }

    private function ids(): array
    {
        return array_map('intval', $this->validate(['selected' => ['required', 'array', 'max:500'], 'selected.*' => ['integer', 'distinct', 'exists:commerce_products,id']])['selected']);
    }

    public function bulk(string $action): void
    {
        app(ProductCatalogue::class)->authorize($this->user(), true);
        abort_unless(in_array($action, ['withdraw', 'archive', 'restore'], true), 422);
        if (! $this->confirmBulk) {
            $this->addError('bulk', 'Confirm the bulk action first.');

            return;
        }
        $ids = $this->ids();
        DB::transaction(function () use ($ids, $action): void {
            foreach (Product::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get() as $product) {
                if ($action === 'restore') {
                    $product->forceFill(['archived_at' => null, 'version' => $product->version + 1])->save();
                    activity('commerce')->performedOn($product)->causedBy($this->user())->log('Product restored as draft');
                } else {
                    app(ProductCatalogue::class)->withdraw($product, $this->user(), $action === 'archive');
                }
            }
        });
        $this->selected = [];
        $this->confirmBulk = false;
        $this->toast('Bulk action completed. Nothing was deleted or bulk-published.');
    }

    public function applyDraftChanges(): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
        if (! $this->confirmBulk) {
            $this->addError('bulk', 'Confirm the selected bulk draft changes.');

            return;
        }
        $this->validate(['bulkCategory' => ['nullable', 'integer', 'exists:commerce_categories,id'], 'bulkSeries' => ['nullable', 'integer', 'exists:commerce_series,id'], 'bulkAvailability' => ['nullable', 'in:available,unavailable,on_request']]);
        if (! $this->bulkCategory && ! $this->bulkSeries && ! $this->bulkAvailability) {
            $this->addError('bulk', 'Choose a category, Series or availability change.');

            return;
        }
        $ids = $this->ids();
        DB::transaction(function () use ($ids): void {
            foreach (Product::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get() as $product) {
                $data = $product->draft;
                if ($this->bulkCategory) {
                    $data['category_ids'] = array_values(array_unique([...$data['category_ids'], (int) $this->bulkCategory]));
                    $data['primary_category_id'] ??= (int) $this->bulkCategory;
                }
                if ($this->bulkSeries) {
                    $data['series_ids'] = array_values(array_unique([...$data['series_ids'], (int) $this->bulkSeries]));
                }
                if ($this->bulkAvailability) {
                    $data['availability'] = $this->bulkAvailability;
                }
                $data['reviewed'] = false;
                app(ProductCatalogue::class)->save($product, $data, $product->version, $this->user());
            }
        });
        $this->confirmBulk = false;
        $this->selected = [];
        $this->toast('Bulk draft changes saved. Published snapshots are unchanged; review each product again.');
    }

    public function export(bool $all = false)
    {
        app(ProductCatalogue::class)->authorize($this->user());
        $ids = $all ? Product::pluck('id')->all() : $this->ids();
        $data = app(ProductTransfer::class)->export($ids);
        activity('commerce')->causedBy($this->user())->withProperties(['count' => count($ids)])->log('Portable product/media package exported');

        return response()->streamDownload(fn () => print (json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)), 'woodex-products.json', ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
    }

    public function render()
    {
        abort_unless($this->user()->can('commerce.catalog.view'), 403);

        $manager = $this->user()->can('commerce.catalog.manage');
        $categories = Category::orderBy('position')->orderBy('id')->get();
        // Resolve once from the real tree. Include inactive descendants for management:
        // their assignments still exist and must not disappear from the audit view.
        $categoryIds = [];
        if ($this->categoryFilter !== '' && preg_match('/^[1-9][0-9]{0,18}$/', $this->categoryFilter) && $categories->contains('id', (int) $this->categoryFilter)) {
            $children = $categories->groupBy('parent_id');
            $pending = [(int) $this->categoryFilter];
            while ($pending) {
                $id = array_pop($pending);
                if (isset($categoryIds[$id])) {
                    continue;
                }
                $categoryIds[$id] = $id;
                foreach ($children->get($id, []) as $child) {
                    $pending[] = $child->id;
                }
            }
        }
        // Group every OR predicate so siblings cannot escape search, publication or
        // archived filters. Unknown/forged filter IDs must match nothing, not all.
        $categoryScope = function ($builder, string $column) use ($categoryIds): void {
            if (! $categoryIds) {
                $builder->whereRaw('1 = 0');

                return;
            }
            $builder->where(function ($group) use ($categoryIds, $column): void {
                foreach ($categoryIds as $id) {
                    $group->orWhereJsonContains($column, $id);
                }
            });
        };
        $query = Product::query();
        if (! $manager) {
            $query->whereNull('archived_at')->whereExists(fn ($q) => $q->selectRaw('1')->from('commerce_product_revisions as snapshot')->whereColumn('snapshot.id', 'commerce_products.published_revision_id')->whereColumn('snapshot.product_id', 'commerce_products.id')->when($this->search !== '', fn ($q) => $q->where('snapshot.content->name', 'like', '%'.mb_substr($this->search, 0, 120).'%'))->when($this->categoryFilter !== '', fn ($q) => $categoryScope($q, 'snapshot.content->category_ids'))->when($this->seriesFilter !== '', fn ($q) => $q->whereJsonContains('snapshot.content->series_ids', (int) $this->seriesFilter)));
        } elseif ($this->search !== '') {
            $query->where('name', 'like', '%'.mb_substr($this->search, 0, 120).'%');
        }
        if ($manager) {
            $query->when($this->categoryFilter !== '', fn ($q) => $categoryScope($q, 'draft->category_ids'))->when($this->seriesFilter !== '', fn ($q) => $q->whereJsonContains('draft->series_ids', (int) $this->seriesFilter));
        }
        $products = $query->when($this->status === 'draft', fn ($q) => $q->whereNull('published_revision_id')->whereNull('archived_at'))
            ->when($this->status === 'published', fn ($q) => $q->whereNotNull('published_revision_id')->whereNull('archived_at'))
            ->when($this->status === 'archived', fn ($q) => $q->whereNotNull('archived_at'))->orderBy($manager ? 'name' : 'id')->paginate(20);
        if (! $manager) {
            $snapshots = DB::table('commerce_product_revisions')->whereIn('id', $products->pluck('published_revision_id'))->get()->keyBy('id');
            foreach ($products as $product) {
                $snapshot = json_decode($snapshots[$product->published_revision_id]->content, true, flags: JSON_THROW_ON_ERROR);
                $product->draft = $snapshot;
                $product->name = $snapshot['name'];
                $product->slug = $snapshot['slug'];
                $product->review_issues = [];
            }
        }
        $categoryPaths = [];
        foreach ($categories as $category) {
            $names = [];
            $seen = [];
            $node = $category;
            while ($node && ! in_array($node->id, $seen, true) && count($seen) < 20) {
                array_unshift($names, $node->name);
                $seen[] = $node->id;
                $node = $categories->firstWhere('id', $node->parent_id);
            }
            $categoryPaths[$category->id] = implode(' → ', $names);
        }

        return view('livewire.admin.commerce.products', [
            'products' => $products,
            'categoryPaths' => $categoryPaths,
            'categories' => $categories,
            'series' => Series::orderBy('position')->get(['id', 'name']),
            'counts' => ['total' => $manager ? Product::count() : Product::whereNotNull('published_revision_id')->whereNull('archived_at')->count(), 'published' => Product::whereNotNull('published_revision_id')->whereNull('archived_at')->count(), 'archived' => $manager ? Product::whereNotNull('archived_at')->count() : 0],
        ]);
    }
}
