<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Commerce;

use App\Domain\Commerce\Models\Category;
use App\Domain\Commerce\Models\CategoryContentRevision;
use App\Domain\Commerce\Services\CategoryContent;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts::admin')]
#[Title('Category content editor')]
final class CategoryEditor extends Component
{
    use InteractsWithAdmin, WithFileUploads;

    #[Locked]
    public Category $category;

    #[Locked]
    public int $version = 0;

    #[Locked]
    public ?string $heroPath = null;

    #[Locked]
    public ?int $savedRevisionId = null;

    public array $form = [];

    public $heroUpload = null;

    public bool $confirmPublish = false;

    public bool $confirmUnpublish = false;

    public function mount(Category $category): void
    {
        $this->manage();
        $this->category = $category;
        $this->version = (int) $category->content_version;
        $revision = $category->draft_revision_id ? CategoryContentRevision::where('category_id', $category->id)->findOrFail($category->draft_revision_id) : null;
        $this->form = $revision?->content ?? app(CategoryContent::class)->defaults($category);
        $this->form['text_format'] ??= 'plain';
        $this->heroPath = $this->form['hero_path'] ?? null;
        unset($this->form['hero_path']);
        $this->savedRevisionId = $revision?->id;
    }

    public function loadSourceText(): void
    {
        $this->manage();
        $source = json_decode(file_get_contents(resource_path('data/category-source-copy.json')), true, flags: JSON_THROW_ON_ERROR);
        $aliases = json_decode(file_get_contents(resource_path('data/commerce-taxonomy.json')), true, flags: JSON_THROW_ON_ERROR)['aliases'];
        $slug = $aliases[$this->category->slug] ?? $this->category->slug;
        $description = $source['descriptions'][$slug] ?? null;
        if (! $description) {
            $this->addError('source', 'No category-specific text exists in the storefront source for this category. Add reviewed content manually.');

            return;
        }
        if (filled($this->form['intro_text'])) {
            $this->addError('source', 'Introduction already contains content. Clear it explicitly before loading source text.');

            return;
        }
        $this->form['intro_text'] = $description;
        $this->confirmPublish = false;
        $this->toast('Existing storefront description loaded into this unsaved draft. Review claims before saving; no images or publication were changed.');
    }

    public function addFaq(): void
    {
        $this->manage();
        if (count($this->form['faqs']) < 20) {
            $this->form['faqs'][] = ['question' => '', 'answer' => ''];
        }
    }

    public function removeFaq(int $index): void
    {
        $this->manage();
        unset($this->form['faqs'][$index]);
        $this->form['faqs'] = array_values($this->form['faqs']);
    }

    public function moveFaq(int $index, int $direction): void
    {
        $this->manage();
        $this->form['faqs'] = $this->move($this->form['faqs'], $index, $direction);
    }

    public function moveProduct(int $index, int $direction): void
    {
        $this->manage();
        $this->form['product_ids'] = $this->move($this->form['product_ids'], $index, $direction);
    }

    public function removeHero(): void
    {
        $this->manage();
        $this->heroPath = null;
        $this->heroUpload = null;
        // Retain files referenced by immutable revisions; no unsafe deletion.
    }

    public function saveDraft(): void
    {
        $this->manage();
        $this->resetValidation();
        $this->validate(['heroUpload' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:max_width=6000,max_height=6000']]);
        $newPath = null;
        try {
            if ($this->heroUpload) {
                $newPath = $this->heroUpload->store('commerce/category-heroes', 'local');
                if (! $newPath) {
                    throw ValidationException::withMessages(['heroUpload' => 'Image storage failed. Your previous draft is unchanged.']);
                }
            }
            $revision = app(CategoryContent::class)->saveDraft($this->category, $this->form, $newPath ?: $this->heroPath, $this->version, $this->user());
        } catch (\Throwable $error) {
            if ($newPath) {
                Storage::disk('local')->delete($newPath);
            }
            throw $error;
        }
        $this->heroPath = $revision->content['hero_path'];
        $this->heroUpload = null;
        $this->savedRevisionId = $revision->id;
        $this->category->refresh();
        $this->version = (int) $this->category->content_version;
        $this->confirmPublish = false;
        $this->toast('Draft saved. Open the exact saved preview before native publication.');
    }

    public function publish(): void
    {
        $this->manage();
        abort_unless($this->user()->can('commerce.catalog.publish'), 403);
        if (! $this->confirmPublish || ! $this->savedRevisionId) {
            $this->addError('publish', 'Save a draft and explicitly confirm native publication.');

            return;
        }
        $revision = CategoryContentRevision::where('category_id', $this->category->id)->findOrFail($this->savedRevisionId);
        $saved = $revision->content;
        unset($saved['hero_path']);
        if ($saved != $this->form || ($revision->content['hero_path'] ?? null) !== $this->heroPath || $this->heroUpload) {
            $this->addError('publish', 'You have unsaved edits. Save and preview a new revision first.');

            return;
        }
        app(CategoryContent::class)->publish($this->category, $revision->id, $this->version, $this->user());
        $this->category->refresh();
        $this->version = (int) $this->category->content_version;
        $this->confirmPublish = false;
        $this->toast('Native snapshot published. Live storefront backend is unchanged.');
    }

    public function restoreAsDraft(int $revisionId): void
    {
        $this->manage();
        $revision = CategoryContentRevision::where('category_id', $this->category->id)->findOrFail($revisionId);
        $this->form = $revision->content;
        $this->form['text_format'] ??= 'plain';
        $this->heroPath = $this->form['hero_path'] ?? null;
        unset($this->form['hero_path']);
        $this->heroUpload = null;
        $this->savedRevisionId = null;
        $this->confirmPublish = false;
        $this->resetValidation();
        $this->toast('Historical content loaded. Save a new draft and preview it before publication.');
    }

    public function unpublish(): void
    {
        $this->manage();
        abort_unless($this->user()->can('commerce.catalog.publish'), 403);
        if (! $this->confirmUnpublish) {
            $this->addError('publish', 'Confirm removing the native publication first.');

            return;
        }
        app(CategoryContent::class)->unpublish($this->category, $this->version, $this->user());
        $this->category->refresh();
        $this->version = (int) $this->category->content_version;
        $this->confirmUnpublish = false;
        $this->toast('Native publication removed. Drafts, history and live storefront are unchanged.');
    }

    private function manage(): void
    {
        abort_unless($this->user()->can('commerce.catalog.view') && $this->user()->can('commerce.catalog.manage'), 403);
    }

    private function move(array $items, int $index, int $direction): array
    {
        $target = $index + $direction;
        if (in_array($direction, [-1, 1], true) && isset($items[$index], $items[$target])) {
            [$items[$index], $items[$target]] = [$items[$target], $items[$index]];
        }

        return array_values($items);
    }

    public function render(): View
    {
        $this->manage();

        return view('livewire.admin.commerce.category-editor', [
            'eligible' => app(CategoryContent::class)->eligibleProducts($this->category),
            'revisions' => CategoryContentRevision::where('category_id', $this->category->id)->latest('id')->limit(10)->get(),
        ]);
    }
}
