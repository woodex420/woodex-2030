<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Sales;

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Sales\Actions\SaveDescriptionTemplate;
use App\Domain\Sales\Models\DescriptionTemplate;
use App\Domain\Sales\Models\Quote;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts::admin')]
#[Title('Description library')]
class DescriptionLibrary extends Component
{
    use InteractsWithAdmin, WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $line = '';

    #[Url(except: '')]
    public string $section = '';

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(): void
    {
        $this->authorize('viewAny', Quote::class);
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['search', 'line', 'section'], true)) {
            $this->resetPage();
        }
    }

    public function openCreate(): void
    {
        $this->authorize('manageCatalog', Quote::class);
        $this->editingId = null;
        $brand = in_array($this->line, BusinessLine::offeredBrandValues(), true) ? $this->line : BusinessLine::primaryBrand()->value;
        $this->form = ['business_line' => $brand, 'section' => '', 'title' => '', 'body' => '', 'is_active' => true];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function openEdit(int $id): void
    {
        $this->authorize('manageCatalog', Quote::class);
        $template = DescriptionTemplate::query()->onSale()->findOrFail($id);
        $this->editingId = $id;
        $this->form = $template->only(['section', 'title', 'body', 'is_active']);
        $this->form['business_line'] = $template->business_line->value;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('manageCatalog', Quote::class);
        // Validate using form-prefixed keys so failures render beside the corresponding controls.
        $this->validate(['form.business_line' => ['required', Rule::in(array_keys($this->formBrands()))], 'form.section' => ['required', 'string', 'max:80'], 'form.title' => ['required', 'string', 'max:100'], 'form.body' => ['required', 'string', 'max:500'], 'form.is_active' => ['required', 'boolean']]);
        app(SaveDescriptionTemplate::class)->handle($this->user(), $this->form, $this->editingId);
        $this->showForm = false;
        $this->toast('Description saved. Existing document copies are unchanged.');
    }

    /**
     * The brands a description may be kept for: the brands on sale. The library lists nothing of another brand,
     * so there is no other brand to keep.
     *
     * @return array<string, string> value => label
     */
    private function formBrands(): array
    {
        return BusinessLine::offeredBrandOptions(['interior' => 'Interior / Project', 'furniture' => 'Furniture']);
    }

    public function render(): View
    {
        return view('livewire.admin.sales.description-library', [
            'templates' => DescriptionTemplate::query()
                ->onSale()
                ->when($this->line !== '', fn (Builder $q) => $q->where('business_line', $this->line))
                ->when($this->section !== '', fn (Builder $q) => $q->where('section', $this->section))
                ->when($this->search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('title', 'like', '%'.$this->search.'%')->orWhere('body', 'like', '%'.$this->search.'%')))
                ->orderBy('business_line')->orderBy('section')->orderBy('title')->paginate(20),
            'sections' => DescriptionTemplate::query()->onSale()->when($this->line !== '', fn (Builder $q) => $q->where('business_line', $this->line))->distinct()->orderBy('section')->pluck('section'),
            'canEdit' => $this->user()->can('manageCatalog', Quote::class),
            'brands' => BusinessLine::offeredBrandOptions(['interior' => 'Interior / Project', 'furniture' => 'Furniture']),
            'formBrands' => $this->showForm ? $this->formBrands() : [],
        ]);
    }
}
