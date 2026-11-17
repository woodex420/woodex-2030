<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Models\Quote;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Livewire\Forms\CompanyForm;
use App\Settings\SalesSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Company page (CRM-002): details, people, enquiries and quotations; invoices join in P3.
 *
 * @property-read Company $company
 */
#[Layout('layouts::admin')]
class CompanyShow extends Component
{
    use InteractsWithAdmin;

    #[Locked]
    public int $companyId;

    public bool $showEdit = false;

    public bool $showDelete = false;

    public CompanyForm $form;

    public string $contactSearch = '';

    public function mount(Company $company): void
    {
        $this->authorize('view', $company);
        $this->companyId = $company->id;
    }

    #[Computed]
    public function company(): Company
    {
        return Company::query()->with('owner:id,name')->findOrFail($this->companyId);
    }

    public function openEdit(): void
    {
        $this->authorize('update', $this->company);
        $this->form->fillFrom($this->company);
        $this->resetValidation();
        $this->showEdit = true;
    }

    public function save(): void
    {
        $this->authorize('update', $this->company);
        $this->form->validate();
        $this->company->update($this->form->payload());

        $this->showEdit = false;
        unset($this->company);
        $this->toast('Company updated.');
    }

    public function linkContact(int $contactId): void
    {
        $this->authorize('update', $this->company);
        $contact = Contact::query()->visibleTo($this->user())->findOrFail($contactId);
        $contact->update(['company_id' => $this->companyId]);
        // Open leads of this person now belong to the company too.
        $contact->leads()->open()->whereNull('company_id')->update(['company_id' => $this->companyId]);

        $this->contactSearch = '';
        $this->toast("{$contact->name} linked to {$this->company->name}.");
    }

    public function unlinkContact(int $contactId): void
    {
        $this->authorize('update', $this->company);
        $contact = Contact::query()->where('company_id', $this->companyId)->findOrFail($contactId);
        $contact->update(['company_id' => null]);

        $this->toast("{$contact->name} removed from {$this->company->name}.");
    }

    public function delete(): void
    {
        $company = $this->company;
        $this->authorize('delete', $company);

        $company->delete();
        $this->toast("{$company->name} moved to the recycle bin.");
        $this->redirectRoute('admin.contacts.index', ['tab' => 'companies'], navigate: true);
    }

    public function render(): View
    {
        $company = $this->company;
        $user = $this->user();
        $contactIds = Contact::query()->where('company_id', $company->id)->pluck('id');

        $leads = Lead::query()
            ->with(['contact:id,name', 'stage', 'owner:id,name'])
            ->where(fn (Builder $q) => $q->where('company_id', $company->id)->orWhereIn('contact_id', $contactIds))
            ->when(! $user->can('leads.view_all'), fn (Builder $q) => $q->where('owner_id', $user->id))
            ->latest('id')
            ->limit(50)
            ->get();

        $term = trim($this->contactSearch);

        return view('livewire.admin.crm.company-show', [
            'company' => $company,
            'contacts' => Contact::query()->visibleTo($user)->where('company_id', $company->id)->withCount('leads')->orderBy('name')->get(),
            'leads' => $leads,
            'quotes' => $user->can('quotes.view')
                ? Quote::query()->visibleTo($user)
                    ->where(fn (Builder $q) => $q->where('company_id', $company->id)->orWhereIn('contact_id', $contactIds))
                    ->latest('id')->limit(30)->get()
                : collect(),
            'lakh' => app(SalesSettings::class)->lakh_grouping,
            'candidates' => mb_strlen($term) >= 2
                ? Contact::query()->visibleTo($user)->search($term)->where(fn (Builder $q) => $q->whereNull('company_id')->orWhere('company_id', '!=', $company->id))->limit(6)->get()
                : collect(),
        ])->title($company->name);
    }
}
