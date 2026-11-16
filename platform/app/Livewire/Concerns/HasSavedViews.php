<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Domain\System\Models\SavedView;
use Illuminate\Support\Collection;

/**
 * Saved views for list screens (GLB-003): store the current filters/sort/columns under a name,
 * re-apply with one click, and optionally open the screen with a default view.
 */
trait HasSavedViews
{
    public string $viewName = '';

    public bool $viewAsDefault = false;

    public bool $showSaveView = false;

    abstract protected function savedViewScreen(): string;

    /**
     * Component properties a view remembers.
     *
     * @return list<string>
     */
    abstract protected function savedViewProperties(): array;

    /**
     * @return Collection<int, SavedView>
     */
    protected function savedViews(): Collection
    {
        return SavedView::query()->where('user_id', $this->user()->id)->where('screen', $this->savedViewScreen())->orderBy('name')->get();
    }

    /**
     * Apply the default view when the screen opens without filters in the URL.
     */
    protected function applyDefaultView(): void
    {
        if (request()->query() !== []) {
            return;
        }

        $view = SavedView::query()->where('user_id', $this->user()->id)->where('screen', $this->savedViewScreen())->where('is_default', true)->first();

        if ($view !== null) {
            $this->fillFromView($view);
        }
    }

    public function saveView(): void
    {
        $this->validate(['viewName' => ['required', 'string', 'max:60']], attributes: ['viewName' => 'view name']);

        $filters = [];
        foreach ($this->savedViewProperties() as $property) {
            $filters[$property] = $this->{$property};
        }

        if ($this->viewAsDefault) {
            SavedView::query()->where('user_id', $this->user()->id)->where('screen', $this->savedViewScreen())->update(['is_default' => false]);
        }

        SavedView::query()->updateOrCreate(
            ['user_id' => $this->user()->id, 'screen' => $this->savedViewScreen(), 'name' => trim($this->viewName)],
            ['filters' => $filters, 'is_default' => $this->viewAsDefault],
        );

        $this->reset('viewName', 'viewAsDefault', 'showSaveView');
        $this->toast('View saved.');
    }

    public function applyView(int $id): void
    {
        $this->fillFromView(SavedView::query()->where('user_id', $this->user()->id)->findOrFail($id));
        $this->resetPage();
    }

    public function deleteView(int $id): void
    {
        SavedView::query()->where('user_id', $this->user()->id)->findOrFail($id)->delete();
        $this->toast('View deleted.');
    }

    private function fillFromView(SavedView $view): void
    {
        foreach ($this->savedViewProperties() as $property) {
            if (array_key_exists($property, $view->filters)) {
                $this->{$property} = $view->filters[$property];
            }
        }
    }
}
