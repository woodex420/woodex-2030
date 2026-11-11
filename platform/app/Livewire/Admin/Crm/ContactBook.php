<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Crm;

use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Settings\SalesSettings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The two views of the contact book that are facts rather than labels (Gauzy parity, slice J):
 *
 * **Customers** — a contact who has actually bought: an accepted quotation or a project. `is_client` is set by the
 * OS when a quote is accepted, but a project can exist without it, so the screen asks the records, not the flag.
 *
 * **Visitors** — someone the website brought in (form, estimator, booking, chat) who has not bought and has no open
 * quotation in play. They are not "analytics": the OS collects no page views, so this screen names exactly what it
 * is — enquiries that have not converted — rather than pretending to show web traffic.
 */
abstract class ContactBook extends Component
{
    use InteractsWithAdmin;
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    /** `customers` or `visitors`. */
    abstract protected function mode(): string;

    public function mount(): void
    {
        $this->authorize('viewAny', Contact::class);
    }

    public function updating(string $name): void
    {
        if ($name === 'search') {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $user = $this->user();
        $mode = $this->mode();

        $base = Contact::query()
            ->visibleTo($user)
            ->search($this->search)
            ->with(['company:id,name', 'owner:id,name']);

        if ($mode === 'customers') {
            $contacts = $base
                ->where(fn ($query) => $query
                    ->whereHas('projects')
                    ->orWhereHas('quotes', fn ($quotes) => $quotes->where('status', QuoteStatus::Accepted)))
                ->withCount([
                    'projects',
                    'quotes as won_quotes_count' => fn ($quotes) => $quotes->where('status', QuoteStatus::Accepted),
                ])
                ->withSum(['quotes as won_value_minor' => fn ($quotes) => $quotes->where('status', QuoteStatus::Accepted)], 'total_minor')
                ->orderByDesc('last_activity_at')
                ->orderByDesc('id')
                ->paginate(25);

            $latestEnquiries = collect();

            return view('livewire.admin.crm.contact-book', [
                'mode' => 'customers',
                'heading' => 'Customers',
                'blurb' => 'Everyone who has bought: a quotation accepted or a project opened. The value is the accepted quotations on the record — not an estimate typed anywhere.',
                'contacts' => $contacts,
                'latestEnquiries' => $latestEnquiries,
                'lakh' => app(SalesSettings::class)->lakh_grouping,
            ]);
        }

        $websiteSources = ['website_form', 'estimator', 'booking', 'website_chat'];

        $contacts = $base
            ->where('is_client', false)
            ->whereDoesntHave('quotes', fn ($quotes) => $quotes->whereIn('status', [QuoteStatus::Accepted]))
            ->whereDoesntHave('projects')
            ->whereHas('leads', fn ($leads) => $leads->whereIn('source', $websiteSources))
            ->withCount([
                'leads',
                'leads as open_leads_count' => fn ($leads) => $leads->open(),
            ])
            ->withMax('leads', 'created_at')
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->paginate(25);

        $latest = Lead::query()
            ->whereIn('contact_id', $contacts->pluck('id'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'contact_id', 'source', 'stage_id', 'created_at'])
            ->unique('contact_id')
            ->keyBy('contact_id');

        return view('livewire.admin.crm.contact-book', [
            'mode' => 'visitors',
            'heading' => 'Visitors',
            'blurb' => 'Enquiries the website brought in — the form, the estimator, bookings and the chat widget — who have not bought and have no accepted quotation. The OS records enquiries, not page views, so this is the honest reach of “visitor”.',
            'contacts' => $contacts,
            'latestEnquiries' => $latest,
            'lakh' => app(SalesSettings::class)->lakh_grouping,
        ]);
    }
}
