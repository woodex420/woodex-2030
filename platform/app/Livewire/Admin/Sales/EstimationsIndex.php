<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Sales;

use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\DraftEstimateFromEstimatorLead;
use App\Domain\Sales\Enums\EstimateStatus;
use App\Domain\Sales\Models\EstimatorRate;
use App\Domain\Sales\Services\EstimateAccess;
use App\Domain\Sales\Services\EstimatorLeadDetails;
use App\Domain\Sales\Services\EstimatorRateBooks;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

#[Layout('layouts::admin')]
#[Title('Estimations')]
class EstimationsIndex extends Component
{
    use InteractsWithAdmin, WithPagination;

    #[Url]
    public string $tab = 'estimates';

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $service = '';

    /** @var array<string, mixed> */
    public array $book = [];

    #[Locked]
    public string $revision = '';

    public string $bookName = '';

    public string $notice = '';

    public function mount(): void
    {
        if (! $this->user()->can('estimates.view') && ! request()->has('tab')) {
            $this->tab = 'rates';
        }
        $this->guardTab();
        if ($this->tab === 'rates') {
            $this->reloadRates();
        }
    }

    private function guardTab(): void
    {
        abort_unless($this->user()->is_active, 403);
        abort_unless(match ($this->tab) {
            'rates' => $this->user()->can('website.edit'),
            'enquiries' => $this->user()->can('estimates.view') && $this->user()->can('leads.view'),
            'estimates' => $this->user()->can('estimates.view'),
            default => false,
        }, 403);
    }

    public function updatedTab(): void
    {
        $this->guardTab();
        $this->resetPage('estimatesPage');
        $this->resetPage('enquiriesPage');
        $this->search = $this->status = $this->service = '';
        if ($this->tab === 'rates' && $this->revision === '') {
            $this->reloadRates();
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage('estimatesPage');
        $this->resetPage('enquiriesPage');
    }

    public function updatedStatus(): void
    {
        $this->resetPage('estimatesPage');
    }

    public function updatedService(): void
    {
        $this->resetPage('estimatesPage');
    }

    public function reloadRates(): void
    {
        abort_unless($this->user()->can('website.edit'), 403);
        try {
            $read = app(EstimatorRateBooks::class)->read($this->user());
            $this->book = $read['book'];
            $this->revision = $read['revision'];
            $this->resetValidation();
            $this->notice = '';
        } catch (Throwable $e) {
            $this->addError('book', $e->getMessage());
        }
    }

    public function publishRates(): void
    {
        abort_unless($this->user()->can('website.edit'), 403);
        $this->resetValidation();
        try {
            $read = app(EstimatorRateBooks::class)->publish($this->user(), $this->book, $this->revision);
            $this->book = $read['book'];
            $this->revision = $read['revision'];
            $this->notice = 'Published. The public estimator, staff and bot now share these service rates.';
        } catch (Throwable $e) {
            $this->addError('book', $e->getMessage());
        }
    }

    public function addService(): void
    {
        abort_unless($this->user()->can('website.edit'), 403);
        if (count($this->book['services'] ?? []) >= 40) {
            return;
        }
        $this->book['services'][] = ['key' => '', 'label' => '', 'hint' => '', 'unit' => 'sqft', 'unitLabel' => 'Area (sq ft)', 'rates' => ['essential' => 0, 'standard' => 0, 'premium' => 0]];
    }

    public function removeService(int $index): void
    {
        abort_unless($this->user()->can('website.edit'), 403);
        unset($this->book['services'][$index]);
        $this->book['services'] = array_values($this->book['services']);
    }

    public function namedBook(string $operation): void
    {
        abort_unless($this->user()->can('website.edit'), 403);
        $this->resetValidation();
        try {
            $result = app(EstimatorRateBooks::class)->template($this->user(), ['op' => $operation, 'name' => $this->bookName]);
            if (isset($result['book'])) {
                $this->book = $result['book'];
            }
            $this->notice = $result['note'];
        } catch (Throwable $e) {
            $this->addError('book', $e->getMessage());
        }
    }

    public function openEnquiry(int $id): void
    {
        abort_unless($this->user()->can('estimates.manage') && $this->user()->can('leads.view'), 403);
        $lead = Lead::query()->findOrFail($id);
        EstimateAccess::lead($lead, $this->user());
        abort_unless($lead->source === LeadSource::Estimator, 422);
        $estimate = EstimateAccess::query($this->user())->where('lead_id', $id)->latest('id')->first()
            ?? app(DraftEstimateFromEstimatorLead::class)->handle($lead, $this->user());
        if ($estimate === null) {
            $this->addError('enquiry', 'Unknown or retired service, or invalid quantity: open the enquiry to confirm the brief.');

            return;
        }
        $this->redirect(route('admin.estimations.show', $estimate));
    }

    public function render(): View
    {
        $this->guardTab();
        $estimates = $enquiries = null;
        $enquiryEstimates = collect();
        $enquiryDetails = [];
        $rates = null;
        if ($this->tab === 'estimates') {
            $estimates = EstimateAccess::query($this->user())->with(['contact:id,name', 'quote:id,number,status', 'creator:id,name', 'lead:id,ref'])
                ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
                ->when($this->service !== '', fn ($q) => $q->where('service_key', $this->service))
                ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q->whereLike('number', '%'.$this->search.'%')->orWhereLike('title', '%'.$this->search.'%')->orWhereHas('contact', fn ($q) => $q->whereLike('name', '%'.$this->search.'%'))))
                ->latest('id')->paginate(20, pageName: 'estimatesPage');
        } elseif ($this->tab === 'enquiries') {
            $enquiries = Lead::query()->where('source', LeadSource::Estimator)->with('contact:id,name')
                ->when(! $this->user()->can('leads.view_all'), fn ($q) => $q->where('owner_id', $this->user()->id))
                ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q->whereLike('ref', '%'.$this->search.'%')->orWhereHas('contact', fn ($q) => $q->whereLike('name', '%'.$this->search.'%'))))
                ->latest('id')->paginate(20, pageName: 'enquiriesPage');
            $enquiryEstimates = EstimateAccess::query($this->user())->with('quote:id,number')->whereIn('lead_id', $enquiries->pluck('id'))->latest('id')->get()->unique('lead_id')->keyBy('lead_id');
            foreach ($enquiries as $lead) {
                $enquiryDetails[$lead->id] = app(EstimatorLeadDetails::class)->read($lead);
            }
        } else {
            try {
                $rates = app(EstimatorRateBooks::class)->read($this->user());
            } catch (Throwable $e) {
                $rates = ['templates' => [], 'consistent' => false, 'error' => $e->getMessage()];
            }
        }

        return view('livewire.admin.sales.estimations-index', ['estimates' => $estimates, 'enquiries' => $enquiries, 'enquiryEstimates' => $enquiryEstimates, 'enquiryDetails' => $enquiryDetails,
            'rates' => $rates, 'statuses' => EstimateStatus::cases(), 'services' => EstimatorRate::query()->pluck('label', 'key')->all()]);
    }
}
