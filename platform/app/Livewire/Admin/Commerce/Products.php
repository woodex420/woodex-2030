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
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts::admin')]
final class Products extends Component
{
    use InteractsWithAdmin, WithPagination;

    public string $search = '';

    public string $status = '';

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
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
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

        return view('livewire.admin.commerce.products', [
            'products' => Product::query()->when(! $this->user()->can('commerce.catalog.manage'), fn ($q) => $q->whereNotNull('published_revision_id')->whereNull('archived_at'))->when($this->search !== '', fn ($q) => $q->where('name', 'like', '%'.mb_substr($this->search, 0, 120).'%'))
                ->when($this->status === 'draft', fn ($q) => $q->whereNull('published_revision_id')->whereNull('archived_at'))
                ->when($this->status === 'published', fn ($q) => $q->whereNotNull('published_revision_id'))
                ->when($this->status === 'archived', fn ($q) => $q->whereNotNull('archived_at'))
                ->orderBy('name')->paginate(20),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'series' => Series::orderBy('position')->get(['id', 'name']),
            'counts' => ['total' => Product::count(), 'published' => Product::whereNotNull('published_revision_id')->count(), 'archived' => Product::whereNotNull('archived_at')->count()],
        ]);
    }
}
