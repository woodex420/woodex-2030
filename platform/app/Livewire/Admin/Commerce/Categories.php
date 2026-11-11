<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Commerce;

use App\Domain\Commerce\Models\Category;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts::admin')]
#[Title('Catalogue categories')]
final class Categories extends Component
{
    use InteractsWithAdmin, WithPagination;

    public string $search = '';

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public array $form = [];

    public function mount(): void
    {
        abort_unless($this->user()->can('commerce.catalog.view'), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->manage();
        $this->editingId = null;
        $this->form = ['name' => '', 'slug' => '', 'description' => '', 'parent_id' => '', 'position' => 0, 'is_active' => false];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->manage();
        $category = Category::query()->findOrFail($id);
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
        DB::transaction(function () use ($data): void {
            // Serialize tree moves; validation must be repeated against the locked current tree.
            $parents = Category::query()->orderBy('id')->lockForUpdate()->pluck('parent_id', 'id');
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
            $category->fill($data)->save();
        });
        $this->showForm = false;
        $this->toast('Category saved. This does not publish changes to the live storefront.');
    }

    private function manage(): void
    {
        abort_unless($this->user()->can('commerce.catalog.view') && $this->user()->can('commerce.catalog.manage'), 403);
    }

    public function render(): View
    {
        abort_unless($this->user()->can('commerce.catalog.view'), 403);

        return view('livewire.admin.commerce.categories', [
            'categories' => Category::query()->when($this->search !== '', fn ($q) => $q->where('name', 'like', '%'.substr($this->search, 0, 120).'%'))
                ->orderBy('position')->orderBy('name')->paginate(20),
            'parents' => Category::query()->orderBy('name')->get(['id', 'name', 'slug', 'parent_id', 'is_active']),
        ]);
    }
}
