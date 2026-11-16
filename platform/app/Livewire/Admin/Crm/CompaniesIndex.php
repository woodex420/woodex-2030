<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Crm;

use App\Domain\Crm\Enums\StageType;
use App\Domain\Crm\Models\Company;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Livewire\Forms\CompanyForm;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Companies (CRM-002): developers, architects and businesses with their people and leads.
 */
#[Layout('layouts::admin')]
#[Title('Companies')]
class CompaniesIndex extends Component
{
    use InteractsWithAdmin, WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: 'name')]
    public string $sort = 'name';

    public bool $showCreate = false;

    public CompanyForm $form;

    public function mount(): void
    {
        $this->authorize('viewAny', Company::class);
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['search', 'type', 'sort'], true)) {
            $this->resetPage();
        }
    }

    public function openCreate(): void
    {
        $this->authorize('create', Company::class);
        $this->form->reset();
        $this->resetValidation();
        $this->showCreate = true;
    }

    public function create(): void
    {
        $this->authorize('create', Company::class);
        $this->form->validate();

        $company = Company::create($this->form->payload() + ['owner_id' => $this->user()->id]);

        $this->showCreate = false;
        $this->toast("{$company->name} added.");
        $this->redirectRoute('admin.companies.show', $company, navigate: true);
    }

    public function render(): View
    {
        $companies = Company::query()
            ->with('owner:id,name')
            ->withCount(['contacts', 'leads as open_leads_count' => fn (Builder $q) => $q->whereHas('stage', fn (Builder $s) => $s->where('type', StageType::Open->value))])
            ->search($this->search)
            ->when($this->type !== '', fn (Builder $q) => $q->where('type', $this->type))
            ->when($this->sort === 'newest', fn (Builder $q) => $q->latest('id'))
            ->when($this->sort === 'leads', fn (Builder $q) => $q->orderByDesc('open_leads_count')->orderBy('name'))
            ->when($this->sort === 'name', fn (Builder $q) => $q->orderBy('name'))
            ->paginate(25);

        return view('livewire.admin.crm.companies-index', [
            'companies' => $companies,
            'types' => Company::TYPES,
        ]);
    }
}
