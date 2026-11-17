<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Crm;

use App\Domain\Crm\Actions\SaveContact;
use App\Domain\Crm\Enums\ClientType;
use App\Domain\Crm\Exceptions\DuplicateContact;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Tag;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Support\Phone;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Contacts list (CRM-001): every person and company we have spoken to, with quick call/WhatsApp.
 */
#[Layout('layouts::admin')]
#[Title('Contacts')]
class ContactsIndex extends Component
{
    use InteractsWithAdmin;
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $tag = '';

    public bool $showCreate = false;

    /** @var array<string, string|bool|null> */
    public array $form = [];

    public ?int $duplicateId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Contact::class);
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['search', 'type', 'tag'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'type', 'tag');
        $this->resetPage();
    }

    public function openCreate(): void
    {
        $this->authorize('create', Contact::class);

        $this->form = ['name' => '', 'phone' => '', 'email' => '', 'city' => 'Lahore', 'area' => '', 'client_type' => 'individual', 'consent_whatsapp' => true];
        $this->duplicateId = null;
        $this->resetValidation();
        $this->showCreate = true;
    }

    public function create(): void
    {
        $this->authorize('create', Contact::class);

        $data = $this->validate(self::rules(), attributes: self::attributes())['form'];

        try {
            $contact = app(SaveContact::class)->handle($data);
        } catch (DuplicateContact $exception) {
            $this->duplicateId = $exception->existing->id;
            $this->addError('form.'.$exception->field, "Already saved as {$exception->existing->name}.");

            return;
        }

        $contact->forceFill(['owner_id' => $this->user()->id])->save();

        $this->showCreate = false;
        $this->toast("{$contact->name} added.");
        $this->redirectRoute('admin.contacts.show', $contact, navigate: true);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'form.name' => ['required', 'string', 'min:2', 'max:160'],
            'form.phone' => ['nullable', 'required_without:form.email', 'string', 'max:30', self::phoneRule()],
            'form.email' => ['nullable', 'required_without:form.phone', 'email', 'max:160'],
            'form.city' => ['nullable', 'string', 'max:80'],
            'form.area' => ['nullable', 'string', 'max:120'],
            'form.client_type' => ['required', Rule::enum(ClientType::class)],
            'form.consent_whatsapp' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return ['form.name' => 'name', 'form.phone' => 'phone', 'form.email' => 'email', 'form.client_type' => 'type'];
    }

    public static function phoneRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (filled($value) && ! Phone::isValid((string) $value)) {
                $fail('Enter a valid phone number, e.g. 0322 4000768.');
            }
        };
    }

    public function render(): View
    {
        $user = $this->user();

        $contacts = Contact::query()
            ->visibleTo($user)
            ->with(['company:id,name', 'tags'])
            ->withCount([
                'leads',
                'leads as open_leads_count' => fn ($q) => $q->open(),
            ])
            ->search($this->search)
            ->when($this->type === 'clients', fn (Builder $q) => $q->where('is_client', true))
            ->when($this->type === 'prospects', fn (Builder $q) => $q->where('is_client', false))
            ->when(in_array($this->type, ClientType::values(), true), fn (Builder $q) => $q->where('client_type', $this->type))
            ->when($this->tag !== '', fn (Builder $q) => $q->whereHas('tags', fn (Builder $t) => $t->where('slug', $this->tag)))
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->paginate(25);

        return view('livewire.admin.crm.contacts-index', [
            'contacts' => $contacts,
            'tags' => Tag::query()->orderBy('name')->get(['name', 'slug']),
            'types' => ClientType::options(),
            'hasFilters' => $this->search !== '' || $this->type !== '' || $this->tag !== '',
        ]);
    }
}
