<?php

declare(strict_types=1);

namespace App\Livewire\Admin\System;

use App\Domain\Crm\Actions\TrashRecords;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Models\Quote;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Recycle bin (GLB-010): restore deleted leads, contacts, companies and draft quotations; the owner can erase a
 * contact's personal data for a privacy request.
 */
#[Layout('layouts::admin')]
#[Title('Recycle bin')]
class RecycleBin extends Component
{
    use InteractsWithAdmin, WithPagination;

    #[Url(except: 'leads')]
    public string $tab = 'leads';

    public function mount(): void
    {
        abort_unless($this->user()->can('leads.delete'), 403);
    }

    public function updatingTab(): void
    {
        $this->resetPage();
    }

    public function restore(string $type, int $id): void
    {
        abort_unless($this->user()->can('leads.delete'), 403);
        $trash = app(TrashRecords::class);

        $name = match ($type) {
            'lead' => tap(Lead::onlyTrashed()->findOrFail($id), fn (Lead $lead) => $trash->restoreLead($lead))->ref,
            'contact' => tap(Contact::onlyTrashed()->findOrFail($id), fn (Contact $contact) => $trash->restoreContact($contact))->name,
            'company' => tap(Company::onlyTrashed()->findOrFail($id), fn (Company $company) => $company->restore())->name,
            'quote' => $this->restoreQuote($id),
            default => abort(404),
        };

        if ($name === null) {
            return;
        }

        $this->toast("{$name} restored.");
    }

    private function restoreQuote(int $id): ?string
    {
        $quote = Quote::onlyTrashed()->with(['contact' => fn ($q) => $q->withTrashed()])->findOrFail($id);

        if ($quote->contact?->trashed()) {
            $this->toast("Restore the contact {$quote->contact->name} first.", 'error');

            return null;
        }

        $quote->restore();

        return $quote->label();
    }

    public function erase(int $contactId): void
    {
        abort_unless($this->user()->hasRole('owner'), 403);

        app(TrashRecords::class)->eraseContact(Contact::withTrashed()->findOrFail($contactId));
        $this->toast('Personal data erased. Totals and the audit trail are kept.');
    }

    public function render(): View
    {
        $records = match ($this->tab) {
            'contacts' => Contact::onlyTrashed()->withCount(['leads' => fn ($q) => $q->withTrashed()])->latest('deleted_at')->paginate(25),
            'companies' => Company::onlyTrashed()->latest('deleted_at')->paginate(25),
            'quotes' => Quote::onlyTrashed()->with(['contact' => fn ($q) => $q->withTrashed(), 'owner:id,name'])->latest('deleted_at')->paginate(25),
            default => Lead::onlyTrashed()->with(['contact' => fn ($q) => $q->withTrashed(), 'owner:id,name'])->latest('deleted_at')->paginate(25),
        };

        return view('livewire.admin.system.recycle-bin', [
            'records' => $records,
            'counts' => [
                'leads' => Lead::onlyTrashed()->count(),
                'contacts' => Contact::onlyTrashed()->count(),
                'companies' => Company::onlyTrashed()->count(),
                'quotes' => Quote::onlyTrashed()->count(),
            ],
            'isOwner' => $this->user()->hasRole('owner'),
        ]);
    }
}
