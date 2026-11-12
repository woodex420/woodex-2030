<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Commerce;

use App\Domain\Commerce\Models\Category;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\ProductMedia;
use App\Domain\Commerce\Models\Series;
use App\Domain\Commerce\Services\ProductCatalogue;
use App\Domain\Commerce\Services\ProductLibrary;
use App\Domain\Commerce\Services\ProductTransfer;
use App\Domain\Website\Models\Media;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    public bool $showPreview = false;

    public bool $showMediaLibrary = false;

    public bool $showReplacement = false;

    public string $mediaSearch = '';

    public string $categorySearch = '';

    public $replacementUpload = null;

    #[Locked]
    public ?int $replacementIndex = null;

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

    public function openPreview(): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
        if (! $this->product) {
            $this->addError('preview', 'Save the product first.');

            return;
        }
        $this->showPreview = true;
    }

    public function chooseReplacement(int $index): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
        abort_unless(isset($this->form['images'][$index]), 422);
        $this->replacementIndex = $index;
        $this->showReplacement = true;
        $this->replacementUpload = null;
    }

    public function replaceImage(): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
        $this->validate(['replacementUpload' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:max_width=6000,max_height=6000']]);
        abort_unless($this->product && $this->replacementIndex !== null && isset($this->form['images'][$this->replacementIndex]), 422);
        $index = $this->replacementIndex;
        $this->saveDraft();
        $media = app(ProductTransfer::class)->storeImage($this->product, file_get_contents($this->replacementUpload->getRealPath()));
        if (in_array($media->id, array_column($this->form['images'], 'id'), true) && (int) $this->form['images'][$index]['id'] !== $media->id) {
            $this->addError('replacementUpload', 'That image is already in this gallery. Select it or reorder instead.');

            return;
        }
        $this->form['images'][$index] = ['id' => $media->id, 'alt' => ''];
        $this->form['reviewed'] = false;
        $this->saveDraft();
        $this->replacementIndex = null;
        $this->replacementUpload = null;
        $this->showReplacement = false;
        $this->toast('Image replaced in the saved draft. Previous files are retained; review the new alt text.');
    }

    public function useLibraryImage(int $id, string $source = 'product'): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
        abort_unless(in_array($source, ['product', 'website'], true), 422);
        if (count($this->form['images']) >= 24) {
            $this->addError('images', 'At most 24 gallery images.');

            return;
        }
        if ($source === 'website') {
            abort_unless($this->user()->can('website.edit'), 403);
            $item = Media::findOrFail($id);
            $path = app(ProductLibrary::class)->websitePath($item);
            $bytes = file_get_contents($path);
            $alt = $item->alt ?? '';
        } else {
            $item = ProductMedia::findOrFail($id);
            abort_unless(preg_match('~^commerce/product-media/[a-f0-9]{64}\.(jpg|png|webp)$~', $item->path) && Storage::disk('local')->exists($item->path), 404);
            $bytes = Storage::disk('local')->get($item->path);
            $alt = '';
        }
        $this->saveDraft();
        $media = app(ProductTransfer::class)->storeImage($this->product, $bytes);
        if (! in_array($media->id, array_column($this->form['images'], 'id'), true)) {
            $this->form['images'][] = ['id' => $media->id, 'alt' => mb_substr($alt, 0, 200)];
            $this->form['reviewed'] = false;
            $this->saveDraft();
        }
        $this->showMediaLibrary = false;
    }

    public function removeCategory(int $id): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
        $this->form['category_ids'] = array_values(array_filter($this->form['category_ids'], fn ($selected) => (int) $selected !== $id));
        if ((int) $this->form['primary_category_id'] === $id) {
            $this->form['primary_category_id'] = '';
        }
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
        if (! $this->product || ! $this->confirmPublish || $this->uploads || $this->replacementUpload) {
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

    public function updatedForm($value, ?string $key = null): void
    {
        if ($key === null) {
            return;
        }
        if ($key === 'primary_category_id' && filled($value) && Category::whereKey($value)->exists() && ! in_array((int) $value, array_map('intval', $this->form['category_ids']), true)) {
            $this->form['category_ids'][] = (int) $value;
        }
        if ($key === 'category_ids' || str_starts_with($key, 'category_ids.')) {
            if (! in_array((int) $this->form['primary_category_id'], array_map('intval', $this->form['category_ids']), true)) {
                $this->form['primary_category_id'] = '';
            }
        }
    }

    public function render()
    {
        app(ProductCatalogue::class)->authorize($this->user());

        $categories = Category::orderBy('position')->orderBy('id')->get();
        $paths = [];
        $visible = [];
        foreach ($categories as $category) {
            $names = [];
            $ids = [];
            $node = $category;
            while ($node && ! in_array($node->id, $ids, true) && count($ids) < 20) {
                array_unshift($names, $node->name);
                $ids[] = $node->id;
                $node = $categories->firstWhere('id', $node->parent_id);
            }
            $paths[$category->id] = implode(' → ', $names);
            if ($this->categorySearch === '' || str_contains(mb_strtolower($paths[$category->id]), mb_strtolower(mb_substr($this->categorySearch, 0, 120)))) {
                $visible = array_merge($visible, $ids);
            }
        }

        return view('livewire.admin.commerce.product-editor', ['categories' => $categories, 'categoryPaths' => $paths, 'visibleCategoryIds' => array_unique($visible), 'series' => Series::orderBy('position')->get(),
            'library' => $this->showMediaLibrary ? ProductMedia::whereIn('product_id', Product::where('name', 'like', '%'.mb_substr($this->mediaSearch, 0, 100).'%')->pluck('id'))->latest('id')->limit(36)->get()->unique('sha256') : collect(),
            'websiteLibrary' => $this->showMediaLibrary && $this->user()->can('website.edit') ? Media::where('disk', 'public')->where('path', 'like', 'website/%')->where(fn ($q) => $q->where('title', 'like', '%'.mb_substr($this->mediaSearch, 0, 100).'%')->orWhere('alt', 'like', '%'.mb_substr($this->mediaSearch, 0, 100).'%'))->latest('id')->limit(24)->get()->filter(fn (Media $media) => app(ProductLibrary::class)->isAvailableWebsiteImage($media)) : collect(),
            'related' => Product::where('id', '!=', $this->product?->id ?? 0)->whereNull('archived_at')->orderBy('name')->get(['id', 'name']),
            'revisions' => $this->product ? DB::table('commerce_product_revisions')->where('product_id', $this->product->id)->latest('id')->limit(10)->get() : collect()]);
    }
}
