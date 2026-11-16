<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Crm;

use App\Domain\Crm\Actions\AnonymiseContact;
use App\Domain\Crm\Actions\CaptureLead;
use App\Domain\Crm\Actions\MergeContacts;
use App\Domain\Crm\Actions\SaveContact;
use App\Domain\Crm\Actions\TrashRecords;
use App\Domain\Crm\Data\LeadInput;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Enums\ClientType;
use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Crm\Exceptions\DuplicateContact;
use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\Tag;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Enums\PaymentStatus;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Livewire\Concerns\ManagesAttachments;
use App\Settings\SalesSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Contact 360 (CRM-001, CRM-012): profile, every enquiry, the full timeline, tags and duplicate merge.
 *
 * @property-read Contact $contact
 */
#[Layout('layouts::admin')]
class ContactShow extends Component
{
    use InteractsWithAdmin, ManagesAttachments;

    #[Locked]
    public int $contactId;

    public bool $showEdit = false;

    /** @var array<string, string|bool|null> */
    public array $form = [];

    public string $newTag = '';

    public bool $showLead = false;

    public string $leadLine = 'interior';

    public string $leadService = '';

    public string $leadMessage = '';

    public function mount(Contact $contact): void
    {
        $this->authorize('view', $contact);
        $this->contactId = $contact->id;
    }

    #[Computed]
    public function contact(): Contact
    {
        return Contact::query()->with(['tags', 'company', 'owner:id,name'])->findOrFail($this->contactId);
    }

    public function deleteContact(): void
    {
        $contact = $this->contact;
        abort_unless($this->user()->can('leads.delete'), 403);

        app(TrashRecords::class)->deleteContact($contact);
        $this->toast("{$contact->name} and their enquiries moved to the recycle bin.");
        $this->redirectRoute('admin.contacts.index', navigate: true);
    }

    /**
     * SYS-013: erase the person, keep the row — the books stay auditable.
     */
    public function anonymiseContact(): void
    {
        abort_unless($this->user()->can('leads.delete'), 403);

        $name = $this->contact->name;
        app(AnonymiseContact::class)->anonymise($this->contact);

        unset($this->contact);
        $this->toast("{$name} anonymised — the row stays for the books, the person is gone from it.", 'warning');
    }

    protected function attachable(): Contact
    {
        return $this->contact;
    }

    public function openEdit(): void
    {
        $contact = $this->contact;
        $this->authorize('update', $contact);

        $this->form = [
            'name' => $contact->name,
            'phone' => $contact->phone,
            'alt_phone' => $contact->alt_phone,
            'whatsapp' => $contact->whatsapp,
            'email' => $contact->email,
            'city' => $contact->city,
            'area' => $contact->area,
            'address' => $contact->address,
            'client_type' => $contact->client_type->value,
            'preferred_language' => $contact->preferred_language,
            'consent_whatsapp' => $contact->consent_whatsapp,
            'consent_marketing' => $contact->consent_marketing,
            'notes' => $contact->notes,
        ];
        $this->resetValidation();
        $this->showEdit = true;
    }

    public function save(): void
    {
        $contact = $this->contact;
        $this->authorize('update', $contact);

        $data = $this->validate([
            'form.name' => ['required', 'string', 'min:2', 'max:160'],
            'form.phone' => ['nullable', 'required_without:form.email', 'string', 'max:30', ContactsIndex::phoneRule()],
            'form.alt_phone' => ['nullable', 'string', 'max:30', ContactsIndex::phoneRule()],
            'form.whatsapp' => ['nullable', 'string', 'max:30', ContactsIndex::phoneRule()],
            'form.email' => ['nullable', 'required_without:form.phone', 'email', 'max:160'],
            'form.city' => ['nullable', 'string', 'max:80'],
            'form.area' => ['nullable', 'string', 'max:120'],
            'form.address' => ['nullable', 'string', 'max:255'],
            'form.client_type' => ['required', Rule::enum(ClientType::class)],
            'form.preferred_language' => ['required', Rule::in(['en', 'ur'])],
            'form.consent_whatsapp' => ['boolean'],
            'form.consent_marketing' => ['boolean'],
            'form.notes' => ['nullable', 'string', 'max:5000'],
        ], attributes: ContactsIndex::attributes())['form'];

        try {
            app(SaveContact::class)->handle($data, $contact);
        } catch (DuplicateContact $exception) {
            $this->addError('form.'.$exception->field, "Already used by {$exception->existing->name} (#{$exception->existing->id}). Merge the two contacts instead.");

            return;
        }

        $this->showEdit = false;
        unset($this->contact);
        $this->toast('Contact saved.');
    }

    public function addTag(): void
    {
        $contact = $this->contact;
        $this->authorize('update', $contact);

        $this->validate(['newTag' => ['required', 'string', 'max:40']], attributes: ['newTag' => 'tag']);

        $slug = Str::slug($this->newTag);

        if ($slug === '') {
            return;
        }

        $tag = Tag::query()->firstOrCreate(['slug' => $slug], ['name' => trim($this->newTag)]);
        $contact->tags()->syncWithoutDetaching([$tag->id]);

        $this->reset('newTag');
        unset($this->contact);
    }

    public function removeTag(int $tagId): void
    {
        $contact = $this->contact;
        $this->authorize('update', $contact);

        $contact->tags()->detach($tagId);
        unset($this->contact);
    }

    public function createLead(): void
    {
        $contact = $this->contact;
        $this->authorize('create', Lead::class);

        $this->validate([
            'leadLine' => ['required', Rule::enum(BusinessLine::class)],
            'leadService' => ['nullable', 'string', 'max:60'],
            'leadMessage' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $this->user();

        $result = app(CaptureLead::class)->handle(new LeadInput(
            name: $contact->name,
            phone: $contact->phone,
            email: $contact->email,
            source: $contact->is_client ? LeadSource::ReturningClient : LeadSource::Manual,
            businessLine: BusinessLine::from($this->leadLine),
            service: $this->leadService !== '' ? $this->leadService : null,
            message: $this->leadMessage !== '' ? $this->leadMessage : null,
            city: $contact->city,
            area: $contact->area,
            ownerId: $user->hasRole('sales') ? $user->id : null,
        ));

        $this->reset('showLead', 'leadService', 'leadMessage');

        $this->toast($result->leadCreated
            ? "Lead {$result->lead->ref} created."
            : "An open {$result->lead->business_line->label()} lead already exists ({$result->lead->ref}); the enquiry was added to it.",
            $result->leadCreated ? 'success' : 'warning');

        $this->redirectRoute('admin.leads.show', $result->lead, navigate: true);
    }

    public function merge(int $duplicateId): void
    {
        $this->authorize('merge', Contact::class);

        $target = $this->contact;
        $duplicate = Contact::query()->findOrFail($duplicateId);

        app(MergeContacts::class)->handle($target, $duplicate, $this->user());

        unset($this->contact);
        $this->toast("{$duplicate->name} merged into this contact.");
    }

    public function render(): View
    {
        $contact = $this->contact;
        $user = $this->user();

        $leads = $contact->leads()
            ->with(['stage', 'owner:id,name'])
            ->latest('id')
            ->get()
            ->filter(fn (Lead $lead) => $user->can('view', $lead));

        $activities = Activity::query()
            ->with(['user:id,name', 'lead:id,ref'])
            ->where('contact_id', $contact->id)
            ->latest('occurred_at')
            ->latest('id')
            ->limit(80)
            ->get();

        $money = null;
        $invoices = collect();

        if ($user->can('invoices.view')) {
            $issued = Invoice::query()
                ->where('contact_id', $contact->id)
                ->where('status', '!=', InvoiceStatus::Void->value);

            $money = [
                'awaiting' => (int) $contact->quotes()
                    ->whereIn('status', [QuoteStatus::Sent->value, QuoteStatus::Viewed->value])
                    ->sum('total_minor'),
                'invoiced' => (int) (clone $issued)->sum('total_minor'),
                'paid' => (int) (clone $issued)->sum('paid_minor'),
                'outstanding' => (int) (clone $issued)
                    ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
                    ->sum('balance_minor'),
                'last_payment' => Payment::query()
                    ->where('contact_id', $contact->id)
                    ->where('status', PaymentStatus::Verified->value)
                    ->max('received_on'),
            ];

            $invoices = Invoice::query()
                ->where('contact_id', $contact->id)
                ->latest('issue_date')
                ->latest('id')
                ->limit(10)
                ->get(['id', 'number', 'status', 'total_minor', 'paid_minor', 'balance_minor', 'issue_date', 'due_date']);
        }

        return view('livewire.admin.crm.contact-show', [
            'contact' => $contact,
            'leads' => $leads,
            'activities' => $activities,
            'files' => $contact->getMedia('files')->sortByDesc('id')->values(),
            'duplicates' => $user->can('merge', Contact::class) ? $contact->possibleDuplicates() : collect(),
            'types' => ClientType::options(),
            'lines' => BusinessLine::options(),
            'quotes' => $user->can('quotes.view') ? $contact->quotes()->visibleTo($user)->latest('id')->limit(20)->get() : collect(),
            'money' => $money,
            'invoices' => $invoices,
            'lakh' => app(SalesSettings::class)->lakh_grouping,
        ])->title($contact->name);
    }
}
