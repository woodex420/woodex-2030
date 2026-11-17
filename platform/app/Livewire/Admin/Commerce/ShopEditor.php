<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Commerce;

use App\Domain\Commerce\Models\Category;
use App\Domain\Commerce\Services\ProductCatalogue;
use App\Domain\Commerce\Services\SeriesContent;
use App\Domain\Commerce\Services\ShopContent;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts::admin')]
final class ShopEditor extends Component
{
    use InteractsWithAdmin;

    #[Locked]
    public int $version = 0;

    #[Locked]
    public ?int $revisionId = null;

    #[Locked]
    public ?int $publishedId = null;

    public array $form = [];

    public bool $confirmPublish = false;

    public bool $confirmWithdraw = false;

    public function mount(): void
    {
        abort_unless($this->user()->can('commerce.catalog.view'), 403);
        $service = app(ShopContent::class);
        $state = $service->state();
        $this->publishedId = $state->published_revision_id === null ? null : (int) $state->published_revision_id;
        if ($this->user()->can('commerce.catalog.manage')) {
            $this->version = (int) $state->version;
            $this->revisionId = $state->draft_revision_id === null ? null : (int) $state->draft_revision_id;
            $this->form = $this->revisionId ? $service->normalize(json_decode($service->revision($this->revisionId)->content, true, flags: JSON_THROW_ON_ERROR)) : $service->defaults();
        }
    }

    public function addSection(): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
        abort_unless(count($this->form['sections']) < 12, 422);
        $this->form['sections'][] = app(ShopContent::class)->section();
        $this->confirmPublish = false;
    }

    public function removeSection(int $index): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
        abort_unless(isset($this->form['sections'][$index]), 422);
        array_splice($this->form['sections'], $index, 1);
        $this->confirmPublish = false;
    }

    public function moveSection(int $index, int $direction): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
        $to = $index + $direction;
        abort_unless(in_array($direction, [-1, 1], true) && isset($this->form['sections'][$index], $this->form['sections'][$to]), 422);
        [$this->form['sections'][$index], $this->form['sections'][$to]] = [$this->form['sections'][$to], $this->form['sections'][$index]];
        $this->confirmPublish = false;
    }

    public function moveProduct(int $section, int $index, int $direction): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
        $to = $index + $direction;
        abort_unless(in_array($direction, [-1, 1], true) && ($this->form['sections'][$section]['type'] ?? '') === 'manual' && isset($this->form['sections'][$section]['product_ids'][$index], $this->form['sections'][$section]['product_ids'][$to]), 422);
        $ids = &$this->form['sections'][$section]['product_ids'];
        [$ids[$index], $ids[$to]] = [$ids[$to], $ids[$index]];
        $this->confirmPublish = false;
    }

    public function clearProducts(int $index): void
    {
        app(ProductCatalogue::class)->authorize($this->user());
        abort_unless(isset($this->form['sections'][$index]), 422);
        $this->form['sections'][$index]['product_ids'] = [];
        $this->confirmPublish = false;
    }

    public function saveDraft(): void
    {
        $this->revisionId = app(ShopContent::class)->save($this->form, $this->version, $this->user());
        $this->version++;
        $this->form = json_decode(app(ShopContent::class)->revision($this->revisionId)->content, true, flags: JSON_THROW_ON_ERROR);
        $this->confirmPublish = false;
        $this->toast('Shop draft saved. Open the saved preview before native publication.');
    }

    public function publish(): void
    {
        app(ProductCatalogue::class)->authorize($this->user(), true);
        $service = app(ShopContent::class);
        if (! $this->confirmPublish || ! $this->revisionId || $service->validate($this->form) !== $service->validate(json_decode($service->revision($this->revisionId)->content, true, flags: JSON_THROW_ON_ERROR))) {
            $this->addError('publish', 'Save edits, open the saved preview and explicitly confirm native publication.');

            return;
        }
        $service->publish($this->revisionId, $this->version, $this->user());
        $this->version++;
        $this->publishedId = $this->revisionId;
        $this->confirmPublish = false;
        $this->toast('Native Shop configuration published. Live storefront remains unchanged.');
    }

    public function withdraw(): void
    {
        app(ProductCatalogue::class)->authorize($this->user(), true);
        if (! $this->confirmWithdraw) {
            $this->addError('publish', 'Explicitly confirm withdrawal.');

            return;
        }
        $service = app(ShopContent::class);
        $service->withdraw($this->version, $this->user());
        $this->version++;
        $this->publishedId = null;
        $this->confirmWithdraw = false;
    }

    public function render()
    {
        abort_unless($this->user()->can('commerce.catalog.view'), 403);
        $manager = $this->user()->can('commerce.catalog.manage');

        return view('livewire.admin.commerce.shop-editor', ['products' => $manager ? app(SeriesContent::class)->eligibleProducts() : [], 'categories' => $manager ? Category::orderBy('name')->get() : collect(), 'collections' => $manager ? DB::table('commerce_series as s')->join('series_content_revisions as r', function ($join): void {
            $join->on('r.id', '=', 's.published_revision_id')->on('r.series_id', '=', 's.id');
        })->get(['s.id', 'r.content'])->map(fn ($row) => ['id' => $row->id, 'heading' => json_decode($row->content, true, flags: JSON_THROW_ON_ERROR)['heading']]) : collect()]);
    }
}
