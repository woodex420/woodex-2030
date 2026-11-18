<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Commerce;

use App\Domain\Commerce\Models\Category;
use App\Domain\Commerce\Models\Series;
use App\Domain\Commerce\Services\CategoryImpact;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts::admin')]
#[Title('Categories')]
final class Categories extends Component
{
    use InteractsWithAdmin, WithPagination;

    public string $search = '';

    #[Locked]
    public string $treeVersion = '';

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public array $form = [];

    #[Locked]
    public array $moveImpact = [];

    #[Locked]
    public string $moveFingerprint = '';

    public bool $confirmMove = false;

    public function updatedFormParentId(): void
    {
        $this->moveImpact = [];
        $this->moveFingerprint = '';
        $this->confirmMove = false;
    }

    private function impactFor(Collection $categories, int $id, ?int $parent): array
    {
        $service = app(CategoryImpact::class);
        $category = $categories->find($id);
        abort_unless($category, 404);
        $branch = $service->branch($categories, $id);
        if ($parent !== null && (in_array($parent, $branch, true) || ! $categories->contains('id', $parent))) {
            throw ValidationException::withMessages(['form.parent_id' => 'Choose an existing parent outside this category branch.']);
        }
        $counts = $service->counts($categories, true)[$id];
        $destination = $service->path($categories, $parent);

        return [
            'from' => $service->path($categories, $category->parent_id),
            'to' => $destination,
            'descendants' => count($branch) - 1,
            'draft_products' => $counts['branch_draft'],
            'published_products' => $counts['branch_published'],
            'inactive_destination' => str_contains($destination, ' · inactive'),
        ];
    }

    private function impactFingerprint(array $impact, ?int $parent): string
    {
        return hash('sha256', json_encode([$this->editingId, $parent, $this->treeVersion, $impact], JSON_THROW_ON_ERROR));
    }

    public function previewMove(): void
    {
        $this->manage();
        $this->validate(['form.parent_id' => ['nullable', 'integer', 'exists:commerce_categories,id']]);
        abort_unless($this->editingId !== null, 422);
        if (! hash_equals($this->treeVersion, $this->treeHash())) {
            throw ValidationException::withMessages(['tree' => 'The tree changed in another session. Reopen Quick edit before moving.']);
        }
        $parent = filled($this->form['parent_id']) ? (int) $this->form['parent_id'] : null;
        $this->resetValidation(['move', 'form.parent_id']);
        $this->moveImpact = $this->impactFor(Category::orderBy('id')->get(), $this->editingId, $parent);
        $this->moveFingerprint = $this->impactFingerprint($this->moveImpact, $parent);
        $this->confirmMove = false;
    }

    public function mount(): void
    {
        abort_unless($this->user()->can('commerce.catalog.view'), 403);
        $this->treeVersion = $this->treeHash();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->manage();
        $this->treeVersion = $this->treeHash();
        $this->updatedFormParentId();
        $this->editingId = null;
        $this->form = ['name' => '', 'slug' => '', 'description' => '', 'parent_id' => '', 'position' => 0, 'is_active' => false];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->manage();
        $category = Category::query()->findOrFail($id);
        $this->treeVersion = $this->treeHash();
        $this->updatedFormParentId();
        $this->editingId = $id;
        $this->form = $category->only(['name', 'slug', 'description', 'parent_id', 'position', 'is_active']);
        $this->form['parent_id'] ??= '';
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->manage();
        $this->form['name'] = trim((string) ($this->form['name'] ?? ''));
        $this->form['slug'] = strtolower(trim((string) ($this->form['slug'] ?? '')));
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:120'],
            'form.slug' => ['required', 'string', 'max:140', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('commerce_categories', 'slug')->ignore($this->editingId)],
            'form.description' => ['nullable', 'string', 'max:3000'],
            'form.parent_id' => ['nullable', 'integer', 'exists:commerce_categories,id'],
            'form.position' => ['required', 'integer', 'between:0,100000'],
            'form.is_active' => ['required', 'boolean'],
        ])['form'];
        $data['parent_id'] = filled($data['parent_id']) ? (int) $data['parent_id'] : null;
        $creating = $this->editingId === null;
        $savedId = DB::transaction(function () use ($data): int {
            // Serialize tree moves; validation must be repeated against the locked current tree.
            $parents = Category::query()->orderBy('id')->lockForUpdate()->pluck('parent_id', 'id');
            if (! hash_equals($this->treeVersion, $this->treeHash())) {
                throw ValidationException::withMessages(['tree' => 'The tree changed in another session. Reopen Quick edit before saving.']);
            }
            $parent = $data['parent_id'];
            $visited = [];
            while ($parent !== null) {
                if ($parent === $this->editingId || isset($visited[$parent])) {
                    throw ValidationException::withMessages(['form.parent_id' => 'A category cannot be its own ancestor.']);
                }
                if (! $parents->has($parent)) {
                    throw ValidationException::withMessages(['form.parent_id' => 'The parent category is no longer available.']);
                }
                $visited[$parent] = true;
                $parent = $parents[$parent];
            }
            $category = $this->editingId === null ? new Category : Category::query()->findOrFail($this->editingId);
            if ($category->exists && $category->parent_id !== $data['parent_id']) {
                $impact = $this->impactFor(Category::orderBy('id')->get(), $category->id, $data['parent_id']);
                if (! $this->confirmMove || ! hash_equals($this->moveFingerprint, $this->impactFingerprint($impact, $data['parent_id']))) {
                    throw ValidationException::withMessages(['move' => 'Preview the current move impact and explicitly confirm before saving. Assignments or the destination may have changed.']);
                }
            }
            $category->fill($data);
            if ($category->exists && $category->isDirty(['name', 'slug', 'parent_id', 'description', 'position', 'is_active'])) {
                $category->content_version = (int) $category->content_version + 1;
            }
            $category->save();

            return $category->id;
        });
        $this->treeVersion = $this->treeHash();
        $this->showForm = false;
        $this->toast('Category saved to the storefront hierarchy. Page content still requires reviewed publication.');
        if ($creating) {
            $this->redirectRoute('admin.commerce.categories.edit', ['category' => $savedId]);
        }
    }

    private function treeHash(): string
    {
        return hash('sha256', Category::orderBy('id')->get(['id', 'parent_id', 'position', 'content_version'])->toJson());
    }

    public function reorder(int $id, int $targetId): void
    {
        $this->manage();
        DB::transaction(function () use ($id, $targetId): void {
            $all = Category::orderBy('id')->lockForUpdate()->get();
            if (! hash_equals($this->treeVersion, $this->treeHash())) {
                throw ValidationException::withMessages(['tree' => 'The tree changed in another session. Reload before organizing.']);
            }
            $moving = $all->find($id);
            $target = $all->find($targetId);
            if (! $moving || ! $target || $moving->parent_id !== $target->parent_id) {
                throw ValidationException::withMessages(['tree' => 'Reorder within the same parent. Use Quick edit to change a parent.']);
            }
            $siblings = $all->where('parent_id', $moving->parent_id)->sortBy(fn ($row) => sprintf('%010d-%010d', $row->position, $row->id))->values();
            $ids = $siblings->pluck('id')->all();
            $from = array_search($id, $ids, true);
            $to = array_search($targetId, $ids, true);
            array_splice($ids, $from, 1);
            array_splice($ids, $to, 0, [$id]);
            foreach ($ids as $position => $categoryId) {
                $row = $all->find($categoryId);
                if ($row->position !== $position) {
                    $row->position = $position;
                    $row->content_version++;
                    $row->save();
                }
            }
            activity('commerce')->causedBy($this->user())->withProperties(['parent_id' => $moving->parent_id, 'ordered_ids' => $ids])->log('Category siblings reordered for storefront');
            $this->treeVersion = $this->treeHash();
        });
        $this->toast('Order saved. Connected storefronts refresh within 15 seconds.');
    }

    private function manage(): void
    {
        abort_unless($this->user()->can('commerce.catalog.view') && $this->user()->can('commerce.catalog.manage'), 403);
    }

    public function render(): View
    {
        abort_unless($this->user()->can('commerce.catalog.view'), 403);

        $parents = Category::query()->orderBy('position')->orderBy('id')->get(['id', 'name', 'slug', 'parent_id', 'is_active']);
        $impact = app(CategoryImpact::class);
        $paths = $parents->mapWithKeys(fn ($category) => [$category->id => $impact->path($parents, $category->id)])->all();
        $excludedParents = $this->editingId ? $impact->branch($parents, $this->editingId) : [];

        return view('livewire.admin.commerce.categories', [
            'categoryCounts' => $impact->counts($parents, $this->user()->can('commerce.catalog.manage')),
            'categoryPaths' => $paths,
            'excludedParents' => $excludedParents,
            'series' => Series::orderBy('position')->get(),
            'categories' => Category::query()->when($this->search !== '', fn ($q) => $q->where('name', 'like', '%'.substr($this->search, 0, 120).'%'))
                ->orderBy('position')->orderBy('name')->paginate(20),
            'parents' => $parents,
        ]);
    }
}
