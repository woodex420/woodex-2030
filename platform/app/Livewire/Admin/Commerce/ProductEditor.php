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
use Livewire\WithFileUploads;

#[Layout('layouts::admin')]
final class ProductEditor extends Component
{
    use InteractsWithAdmin, WithFileUploads;

    #[Locked]
    public ?Product $product = null;

    #[Locked]
    public int $version = 0;

    public array $form = [];

    public array $uploads = [];

    public bool $confirmPublish = false;

    public bool $confirmWithdraw = false;

    public function mount(?Product $product = null): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
        $this->product = $product?->exists ? $product : null;
        $this->version = $this->product?->version ?? 0;
        $this->form = $this->product?->draft ?? app(ProductCatalogue::class)->defaults();
    }

    public function saveDraft(): void
    {
        $this->product = app(ProductCatalogue::class)->save($this->product, $this->form, $this->version, $this->user());
        $this->version = $this->product->version;
        $this->form = $this->product->draft;
        $this->confirmPublish = false;
        $this->toast('Product draft saved. Existing storefront products are unchanged.');
    }

    public function uploadImages(): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
        $this->validate(['uploads' => ['required', 'array', 'max:24'], 'uploads.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:max_width=6000,max_height=6000']]);
        if (count($this->form['images']) + count($this->uploads) > 24) {
            $this->addError('uploads', 'At most 24 gallery images.');

            return;
        }
        // Validate identity/content before attaching media to a new record.
        $this->saveDraft();
        DB::transaction(function (): void {
            foreach ($this->uploads as $upload) {
                $media = app(ProductTransfer::class)->storeImage($this->product, file_get_contents($upload->getRealPath()));
                if (! in_array($media->id, array_column($this->form['images'], 'id'))) {
                    $this->form['images'][] = ['id' => $media->id, 'alt' => ''];
                }
            }
            $this->saveDraft();
        });
        $this->uploads = [];
        $this->toast('Gallery updated and draft saved. First image is primary; add alternative text before publishing.');
    }

    public function addRow(string $field): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
        $defaults = ['specifications' => ['label' => '', 'value' => ''], 'features' => '', 'faqs' => ['question' => '', 'answer' => ''],
            'variants' => ['sku' => '', 'colour' => '', 'material' => '', 'size' => '', 'price' => '', 'availability' => 'on_request']];
        abort_unless(array_key_exists($field, $defaults), 422);
        if (count($this->form[$field]) < ($field === 'variants' ? 100 : 30)) {
            $this->form[$field][] = $defaults[$field];
        }
    }

    public function removeRow(string $field, int $index): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
        abort_unless(in_array($field, ['images', 'specifications', 'features', 'faqs', 'variants'], true), 422);
        unset($this->form[$field][$index]);
        $this->form[$field] = array_values($this->form[$field]);
    }

    public function moveRow(string $field, int $index, int $direction): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
        abort_unless(in_array($field, ['images', 'specifications', 'features', 'faqs', 'variants', 'related_ids'], true) && in_array($direction, [-1, 1], true), 422);
        $target = $index + $direction;
        if (isset($this->form[$field][$index], $this->form[$field][$target])) {
            [$this->form[$field][$index], $this->form[$field][$target]] = [$this->form[$field][$target], $this->form[$field][$index]];
        }
    }

    public function publish(): void
    {
        $service = app(ProductCatalogue::class);
        $service->authorize($this->user(), true);
        if (! $this->product || ! $this->confirmPublish || $this->uploads) {
            $this->addError('publish', 'Save, preview and explicitly confirm the reviewed product first.');

            return;
        }
        $fresh = $this->product->fresh();
        if ($service->validate($this->form, $fresh) != $fresh->draft) {
            $this->addError('publish', 'Unsaved edits must be saved and previewed first.');

            return;
        }
        $service->publish($fresh, $this->version, $this->user());
        $this->product->refresh();
        $this->version = $this->product->version;
        $this->confirmPublish = false;
        $this->toast('Reviewed native product snapshot published. Live product backend cutover remains separate from category sync.');
    }

    public function withdraw(): void
    {
        app(ProductCatalogue::class)->authorize($this->user(), true);
        if (! $this->product || ! $this->confirmWithdraw) {
            $this->addError('publish', 'Confirm withdrawal first.');

            return;
        }
        app(ProductCatalogue::class)->withdraw($this->product, $this->user());
        $this->product->refresh();
        $this->version = $this->product->version;
        $this->confirmWithdraw = false;
    }

    public function restoreRevision(int $id): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
        abort_unless($this->product, 404);
        $revision = DB::table('commerce_product_revisions')->where('product_id', $this->product->id)->find($id);
        abort_unless($revision, 404);
        $this->form = json_decode($revision->content, true, flags: JSON_THROW_ON_ERROR);
        $this->form['reviewed'] = false;
        $this->confirmPublish = false;
        $this->toast('Historical content loaded as unsaved changes. Review, save and preview it again.');
    }

    public function render()
    {
        app(ProductCatalogue::class)->authorize($this->user());

        return view('livewire.admin.commerce.product-editor', ['categories' => Category::orderBy('parent_id')->orderBy('position')->get(), 'series' => Series::orderBy('position')->get(),
            'related' => Product::where('id', '!=', $this->product?->id ?? 0)->whereNull('archived_at')->orderBy('name')->get(['id', 'name']),
            'revisions' => $this->product ? DB::table('commerce_product_revisions')->where('product_id', $this->product->id)->latest('id')->limit(10)->get() : collect()]);
    }
}
