<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Sales;

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Enums\StageType;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\QuoteTemplate;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Start a quotation (QUO-003): pick the client (or come from an enquiry), the line and a template or a blank page.
 * The number is allocated when the draft is created (QUO-006).
 */
#[Layout('layouts::admin')]
#[Title('New quotation')]
class QuoteCreate extends Component
{
    use InteractsWithAdmin;

    /** Enquiry id, 0 = not linked. */
    #[Url(as: 'lead', except: 0)]
    public int $leadId = 0;

    #[Url(as: 'contact', except: 0)]
    public int $contactId = 0;

    /** Template id, 0 = blank quotation, null = not chosen yet. */
    #[Url(as: 'template')]
    public ?int $templateId = null;

    public string $line = 'interior';

    public bool $brandChosen = false;

    public string $title = '';

    public function chooseBrand(string $brand): void
    {
        if (! in_array($brand, ['interior', 'furniture'], true)) {
            return;
        }
        $this->brandChosen = true;
        $this->line = $brand;
        $this->updatedLine();
    }

    public function updatedLine(): void
    {
        if ($this->templateId && ($template = QuoteTemplate::find($this->templateId)) && (($template->business_line === BusinessLine::Furniture) !== ($this->line === 'furniture'))) {
            $this->templateId = null;
        }
    }

    public string $contactSearch = '';

    public function mount(): void
    {
        $this->authorize('create', Quote::class);

        if ($this->leadId > 0) {
            $lead = Lead::query()->find($this->leadId);

            if ($lead !== null && $this->user()->can('view', $lead)) {
                $this->contactId = $lead->contact_id;
                $this->line = $lead->business_line->value;
            } else {
                $this->leadId = 0;
            }
        }

        if ($this->contactId > 0 && ! $this->visibleContact($this->contactId)) {
            $this->contactId = 0;
            $this->leadId = 0;
        }

        if ($this->templateId) {
            $template = QuoteTemplate::query()->where('is_active', true)->find($this->templateId);
            $this->templateId = $template?->id;

            if ($template !== null && $this->leadId === 0) {
                $this->line = $template->business_line->value;
            }
        }
    }

    public function selectContact(int $contactId): void
    {
        if (! $this->visibleContact($contactId)) {
            return;
        }

        $this->contactId = $contactId;
        $this->contactSearch = '';

        $openLeads = $this->openLeads($contactId);
        $only = $openLeads->count() === 1 ? $openLeads->first() : null;
        $this->leadId = $only->id ?? 0;

        if ($only !== null && ! $this->brandChosen) {
            $this->line = $only->business_line->value;
        }
    }

    public function clearContact(): void
    {
        $this->contactId = 0;
        $this->leadId = 0;
    }

    public function chooseTemplate(int $templateId): void
    {
        if ($templateId === 0) {
            $this->templateId = 0;

            return;
        }

        $template = QuoteTemplate::query()->where('is_active', true)->find($templateId);

        if ($template !== null) {
            $this->templateId = $template->id;

            if ($template->business_line === BusinessLine::Furniture || $this->line === BusinessLine::Furniture->value) {
                $this->line = $template->business_line->value;
            }
        }
    }

    public function create(): void
    {
        $this->authorize('create', Quote::class);

        $this->validate([
            'contactId' => ['required', 'integer', 'min:1'],
            'leadId' => ['integer', 'min:0'],
            'line' => ['required', Rule::enum(BusinessLine::class)],
            'templateId' => ['required', 'integer', 'min:0'],
            'title' => ['nullable', 'string', 'max:200'],
        ], [
            'contactId.required' => 'Choose the client for this quotation.',
            'contactId.min' => 'Choose the client for this quotation.',
            'templateId.required' => 'Choose a template or start blank.',
        ]);

        $contact = $this->visibleContact($this->contactId);

        if ($contact === null) {
            $this->addError('contactId', 'Choose the client for this quotation.');

            return;
        }

        $lead = null;

        if ($this->leadId > 0) {
            $lead = Lead::query()->where('contact_id', $contact->id)->find($this->leadId);

            if ($lead === null || ! $this->user()->can('view', $lead)) {
                $this->addError('leadId', 'That enquiry does not belong to this client.');

                return;
            }
        }

        $template = $this->templateId > 0 ? QuoteTemplate::query()->where('is_active', true)->find($this->templateId) : null;

        if ($this->templateId > 0 && $template === null) {
            $this->addError('templateId', 'That template is no longer available.');

            return;
        }

        $quote = app(CreateQuote::class)->handle($contact, BusinessLine::from($this->line), $this->user(), $lead, $template, $this->title);

        $this->toast("Quotation {$quote->label()} created.");
        $this->redirectRoute('admin.quotes.show', $quote, navigate: true);
    }

    public function render(): View
    {
        $contact = $this->contactId > 0 ? Contact::query()->with('company:id,name')->find($this->contactId) : null;
        $term = trim($this->contactSearch);

        return view('livewire.admin.sales.quote-create', [
            'contact' => $contact,
            'openLeads' => $contact !== null ? $this->openLeads($contact->id) : collect(),
            'results' => $contact === null && mb_strlen($term) >= 2
                ? Contact::query()->visibleTo($this->user())->search($term)->with('company:id,name')->orderBy('name')->limit(8)->get()
                : collect(),
            'templates' => QuoteTemplate::query()->where('is_active', true)->where('business_line', $this->line === 'furniture' ? 'furniture' : 'interior')->orderBy('position')->get(),
            'lines' => BusinessLine::options(),
        ]);
    }

    private function visibleContact(int $contactId): ?Contact
    {
        /** @var Contact|null $contact */
        $contact = Contact::query()->visibleTo($this->user())->find($contactId);

        return $contact;
    }

    /**
     * @return Collection<int, Lead>
     */
    private function openLeads(int $contactId): Collection
    {
        return Lead::query()
            ->visibleTo($this->user())
            ->with('stage')
            ->where('contact_id', $contactId)
            ->whereHas('stage', fn (Builder $q) => $q->where('type', StageType::Open->value))
            ->latest('id')
            ->get();
    }
}
