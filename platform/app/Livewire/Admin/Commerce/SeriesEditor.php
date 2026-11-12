<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Commerce;

use App\Domain\Commerce\Models\Category;
use App\Domain\Commerce\Models\Series;
use App\Domain\Commerce\Services\ProductCatalogue;
use App\Domain\Commerce\Services\ProductLibrary;
use App\Domain\Commerce\Services\SeriesContent;
use App\Domain\Commerce\Services\SeriesHero;
use App\Domain\Website\Models\Media;
use App\Livewire\Concerns\InteractsWithAdmin;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts::admin')]
final class SeriesEditor extends Component
{
    use InteractsWithAdmin, \Livewire\WithFileUploads;

    #[Locked]
    public Series $series;

    #[Locked]
    public int $version = 0;

    #[Locked]
    public ?int $revisionId = null;

    public array $form = [];

    #[Locked]
    public ?string $heroPath = null;

    public $heroUpload;

    public string $heroSearch = '';

    public function updatedHeroSearch(): void
    {
        $this->resetPage('heroMediaPage');
    }

    public function selectHero(int $id): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
        abort_unless($this->user()->can('website.edit'), 403);
        $media = Media::findOrFail($id);
        $path = app(ProductLibrary::class)->websitePath($media);
        $bytes = @file_get_contents($path);
        abort_unless(is_string($bytes), 404);
        $this->heroPath = app(SeriesHero::class)->store($bytes);
        $this->form['hero_alt'] = $media->alt ?? '';
        $this->confirmPublish = false;
    }

    public function uploadHero(): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
        $this->validate(['heroUpload' => ['required', 'file', 'max:4096']]);
        $this->heroPath = app(SeriesHero::class)->store(file_get_contents($this->heroUpload->getRealPath()));
        $this->heroUpload = null;
        $this->confirmPublish = false;
    }

    public function removeHero(): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
        $this->heroPath = null;
        $this->confirmPublish = false;
    }

    public function addFaq(): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
        abort_unless(count($this->form['faqs']) < 20, 422);
        $this->form['faqs'][] = ['question' => '', 'answer' => ''];
    }

    public function removeFaq(int $index): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
        abort_unless(isset($this->form['faqs'][$index]), 422);
        array_splice($this->form['faqs'], $index, 1);
    }

    public bool $confirmPublish = false;

    public bool $confirmWithdraw = false;

    public function mount(Series $series): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
        $this->series = $series;
        $this->version = $series->content_version;
        $this->revisionId = $series->draft_revision_id;
        $service = app(SeriesContent::class);
        $this->form = $this->revisionId ? $service->normalize(json_decode($service->revision($series, $this->revisionId)->content, true, flags: JSON_THROW_ON_ERROR)) : $service->defaults($series);
        $this->heroPath = $this->form['hero_path'];
    }

    public function saveDraft(): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
        if ($this->heroUpload) {
            $this->addError('hero', 'Stage the selected upload or clear it before saving the collection draft.');

            return;
        }
        $this->revisionId = app(SeriesContent::class)->save($this->series, $this->form, $this->version, $this->user(), $this->heroPath);
        $this->series->refresh();
        $this->version = $this->series->content_version;
        $this->form = json_decode(app(SeriesContent::class)->revision($this->series, $this->revisionId)->content, true, flags: JSON_THROW_ON_ERROR);
        $this->confirmPublish = false;
        $this->toast('Collection draft saved. Open its saved preview before native publication.');
    }

    public function moveProduct(int $index, int $direction): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
        $to = $index + $direction;
        abort_unless(in_array($direction, [-1, 1], true) && isset($this->form['product_ids'][$index], $this->form['product_ids'][$to]), 422);
        [$this->form['product_ids'][$index], $this->form['product_ids'][$to]] = [$this->form['product_ids'][$to], $this->form['product_ids'][$index]];
        $this->confirmPublish = false;
    }

    public function removeProduct(int $index): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
        abort_unless(isset($this->form['product_ids'][$index]), 422);
        array_splice($this->form['product_ids'], $index, 1);
        $this->confirmPublish = false;
    }

    public function publish(): void
    {
        app(ProductCatalogue::class)->authorize($this->user(), true);
        $service = app(SeriesContent::class);
        if (! $this->confirmPublish || ! $this->revisionId || $this->heroUpload || $service->validate($this->form, $this->heroPath) !== $service->validate($saved = json_decode($service->revision($this->series, $this->revisionId)->content, true, flags: JSON_THROW_ON_ERROR), $saved['hero_path'] ?? null)) {
            $this->addError('publish', 'Save your edits, open the saved preview and explicitly confirm publication.');

            return;
        }
        $service->publish($this->series, $this->revisionId, $this->version, $this->user());
        $this->series->refresh();
        $this->version = $this->series->content_version;
        $this->confirmPublish = false;
        $this->toast('Native collection snapshot published. React storefront cutover remains separate.');
    }

    public function withdraw(): void
    {
        app(ProductCatalogue::class)->authorize($this->user(), true);
        if (! $this->confirmWithdraw) {
            $this->addError('publish', 'Explicitly confirm withdrawal.');

            return;
        }
        app(SeriesContent::class)->withdraw($this->series, $this->version, $this->user());
        $this->series->refresh();
        $this->version = $this->series->content_version;
        $this->confirmWithdraw = false;
        $this->toast('Native publication withdrawn; drafts, revisions and legacy storefront remain unchanged.');
    }

    public function render()
    {
        app(ProductCatalogue::class)->authorize($this->user());

        $heroMedia = $this->user()->can('website.edit') ? Media::where('disk', 'public')->where('path', 'like', 'website/%')->where('title', 'like', '%'.mb_substr($this->heroSearch, 0, 100).'%')->latest('id')->paginate(12, ['*'], 'heroMediaPage') : null;
        if ($heroMedia) {
            $heroMedia->setCollection($heroMedia->getCollection()->filter(fn ($media) => app(ProductLibrary::class)->isAvailableWebsiteImage($media)));
        }

        return view('livewire.admin.commerce.series-editor', ['products' => app(SeriesContent::class)->eligibleProducts(), 'heroMedia' => $heroMedia, 'categories' => Category::orderBy('name')->get()]);
    }
}
