<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Sales;

use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\ComputeEstimate;
use App\Domain\Sales\Actions\ConvertEstimateToQuote;
use App\Domain\Sales\Actions\ReviewEstimate;
use App\Domain\Sales\Actions\SaveEstimate;
use App\Domain\Sales\Enums\EstimateStatus;
use App\Domain\Sales\Models\CatalogItem;
use App\Domain\Sales\Models\Estimate;
use App\Domain\Sales\Models\EstimatorRate;
use App\Domain\Sales\Services\EstimateAccess;
use App\Domain\Sales\Services\EstimatorLeadDetails;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * P8.3: the estimate builder — pick a service, give the size and finish, set the honest
 * contingency, add known rate book items, and watch the same server-side engine price it that
 * will price the snapshot. One click converts it to a quotation draft; the estimate itself never
 * reaches a client.
 */
#[Layout('layouts::admin')]
#[Title('Estimate builder')]
class EstimateBuilder extends Component
{
    use InteractsWithAdmin;

    #[Locked]
    public ?int $estimateId = null;

    /** The enquiry this estimate is being built for (?lead=…), so the quotation keeps the thread. */
    #[Locked]
    public ?int $leadId = null;

    #[Locked]
    public string $number = '';

    public string $title = '';

    public string $serviceKey = '';

    public string $contactId = '';

    public string $quantity = '';

    public string $finish = 'standard';

    public string $contingencyBp = '0';

    public string $assumptions = '';

    public string $exclusions = '';

    /** @var list<array{catalog_item_id: string, qty: string}> */
    public array $catalogLines = [];

    #[Locked]
    public string $status = 'draft';

    #[Locked]
    public string $savedInputHash = '';

    public bool $confirmReprice = false;

    #[Locked]
    public string $savedRecordHash = '';

    public function mount(?int $estimate = null, ?int $lead = null): void
    {
        abort_unless($this->user()->can('estimates.view'), 403);

        // Full-page Livewire routes get route parameters, not the query string: the lead-page
        // button links to ?lead=…, so read it here.
        if ($lead === null) {
            $lead = request()->integer('lead') ?: null;
        }

        if ($estimate === null) {
            if ($lead === null) {
                return;
            }

            $this->startFromLead(Lead::query()->with('contact:id,name')->findOrFail($lead));

            return;
        }

        $model = Estimate::query()->findOrFail($estimate);
        EstimateAccess::authorize($model, $this->user());
        $this->leadId = $model->lead_id;

        $this->estimateId = $model->id;
        $this->number = $model->number;
        $this->title = $model->title;
        $this->serviceKey = $model->service_key;
        $this->contactId = (string) ($model->contact_id ?? '');
        $this->quantity = (string) $model->inputs['quantity'];
        $this->finish = (string) $model->inputs['finish'];
        $this->contingencyBp = (string) $model->contingency_bp;
        $this->assumptions = (string) $model->assumptions;
        $this->exclusions = (string) $model->exclusions;
        $this->catalogLines = collect($model->catalog_lines ?? [])
            ->map(static fn (array $line): array => ['catalog_item_id' => (string) $line['catalog_item_id'], 'qty' => (string) $line['qty']])
            ->values()
            ->all();
        $this->status = $model->status->value;
        $this->savedInputHash = $this->inputHash();
        $this->savedRecordHash = $this->recordHash($model);
    }

    /**
     * A phone call or a website enquiry already told us the service and the size; the builder opens
     * with those fields filled and the enquiry linked, so the estimate is not typed twice. If the
     * enquiry's service is not in the rate book (an old label, a bespoke job) the service stays
     * unset and the person picks the right line — never a guessed rate.
     */
    private function startFromLead(Lead $lead): void
    {
        EstimateAccess::lead($lead, $this->user());
        $this->leadId = $lead->id;
        $this->contactId = (string) ($lead->contact_id ?? '');

        if ($lead->area_sqft !== null && $lead->area_sqft > 0) {
            $this->quantity = (string) $lead->area_sqft;
        }

        $read = app(EstimatorLeadDetails::class)->read($lead);
        if ($read['area'] > 0) {
            $this->quantity = (string) $read['area'];
        }
        $this->finish = $read['finish'];
        $rate = $read['rate'];

        if ($rate === null && $lead->service !== null) {
            $rate = EstimatorRate::query()->where('key', $lead->service)->where('is_active', true)->first();
        }

        $this->serviceKey = $rate instanceof EstimatorRate ? $rate->key : '';
        $this->title = $rate !== null
            ? $rate->label.' — '.$lead->contact->name
            : 'Estimate for '.$lead->contact->name.' ('.$lead->ref.')';
    }

    public function render(): View
    {
        if ($this->estimateId !== null) {
            EstimateAccess::authorize(Estimate::query()->findOrFail($this->estimateId), $this->user());
        }
        if ($this->leadId !== null) {
            EstimateAccess::lead(Lead::query()->findOrFail($this->leadId), $this->user());
        }

        return view('livewire.admin.sales.estimate-builder', [
            'services' => EstimatorRate::query()->where('is_active', true)->orderBy('position')->get(['id', 'key', 'label', 'unit', 'unit_label', 'essential_minor', 'standard_minor', 'premium_minor']),
            'contacts' => EstimateAccess::contacts($this->user())->orderBy('name')->limit(300)->get(['id', 'name']),
            'catalogItems' => CatalogItem::query()->where('is_active', true)->orderBy('category')->orderBy('description')->get(['id', 'category', 'description', 'unit', 'rate_minor', 'business_line']),
            'summary' => $this->summary(),
            'estimate' => $this->estimateId !== null ? Estimate::query()->with('quote:id,number,status')->find($this->estimateId) : null,
            'lead' => $this->leadId !== null ? Lead::query()->find($this->leadId, ['id', 'ref', 'contact_id', 'service', 'area_sqft']) : null,
        ]);
    }

    /**
     * Live pricing from the same engine that will freeze the snapshot.
     *
     * @return array{rate_minor: string, headline: string, range_low: string, range_high: string, lines_minor: string, contingency: string, total: string, effective_on: string}|null
     */
    public function summary(): ?array
    {
        if ($this->status === 'converted' && $this->estimateId !== null) {
            return $this->savedSummary();
        }
        $rate = $this->currentRate();

        if ($rate === null) {
            return null;
        }

        try {
            $computed = app(ComputeEstimate::class)->handle($rate, $this->quantity, $this->finish, array_values(array_filter($this->normalizedLines(), static fn (array $line): bool => $line['catalog_item_id'] > 0 && $line['qty'] !== '')), (int) $this->contingencyBp);
        } catch (ValidationException) {
            return null;
        }

        return [
            'rate_minor' => Money::ofMinor($computed['rate_minor'])->format(),
            'headline' => Money::ofMinor($computed['headline_minor'])->format(),
            'range_low' => Money::ofMinor($computed['range_low_minor'])->format(),
            'range_high' => Money::ofMinor($computed['range_high_minor'])->format(),
            'lines_minor' => Money::ofMinor($computed['lines_minor'])->format(),
            'contingency' => Money::ofMinor($computed['contingency_minor'])->format(),
            'total' => Money::ofMinor($computed['total_minor'])->format(),
            'effective_on' => $computed['effective_on'],
        ];
    }

    public function addLine(): void
    {
        $this->catalogLines[] = ['catalog_item_id' => '', 'qty' => '1'];
    }

    public function removeLine(int $index): void
    {
        unset($this->catalogLines[$index]);
        $this->catalogLines = array_values($this->catalogLines);
    }

    /** @return array{rate_minor: string, headline: string, range_low: string, range_high: string, lines_minor: string, contingency: string, total: string, effective_on: string}|null */
    public function savedSummary(): ?array
    {
        if ($this->estimateId === null) {
            return null;
        }
        $estimate = Estimate::query()->findOrFail($this->estimateId);
        EstimateAccess::authorize($estimate, $this->user());
        $c = $estimate->computed;

        return ['rate_minor' => Money::ofMinor((int) $c['rate_minor'])->format(), 'headline' => Money::ofMinor((int) $c['headline_minor'])->format(),
            'range_low' => Money::ofMinor((int) $c['range_low_minor'])->format(), 'range_high' => Money::ofMinor((int) $c['range_high_minor'])->format(),
            'lines_minor' => Money::ofMinor((int) $c['lines_minor'])->format(), 'contingency' => Money::ofMinor((int) $c['contingency_minor'])->format(),
            'total' => Money::ofMinor((int) $c['total_minor'])->format(), 'effective_on' => (string) $c['effective_on']];
    }

    public function save(): void
    {
        abort_unless($this->user()->can('estimates.manage'), 403);
        $old = $this->estimateId !== null ? Estimate::query()->findOrFail($this->estimateId) : null;
        if ($old !== null) {
            EstimateAccess::authorize($old, $this->user());
            abort_if($old->status === EstimateStatus::Converted, 403);
            $this->checkRecord($old);
            $preview = $this->summary();
            if ($preview !== null && ($preview['total'] !== Money::ofMinor((int) $old->computed['total_minor'])->format()
                || $preview['rate_minor'] !== Money::ofMinor((int) $old->computed['rate_minor'])->format()) && ! $this->confirmReprice) {
                $this->addError('confirmReprice', 'Compare the saved and current amounts, then confirm repricing before saving.');

                return;
            }
        }
        try {
            $estimate = app(SaveEstimate::class)->handle($this->payload(), $this->user(), $old);
        } catch (ValidationException $e) {
            $errors = [];
            foreach ($e->errors() as $key => $messages) {
                $parts = explode('.', $key);
                $parts[0] = ['service_key' => 'serviceKey', 'contact_id' => 'contactId', 'lead_id' => 'leadId', 'contingency_bp' => 'contingencyBp', 'catalog_lines' => 'catalogLines'][$parts[0]] ?? $parts[0];
                $errors[implode('.', $parts)] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
        $this->estimateId = $estimate->id;
        $this->number = $estimate->number;
        $this->status = $estimate->status->value;
        $this->savedInputHash = $this->inputHash();
        $this->savedRecordHash = $this->recordHash($estimate);
        $this->confirmReprice = false;
        $this->resetValidation();
        $this->toast('Estimate '.$estimate->number.' saved with today\'s rates.', 'success');
    }

    private function ready(): ?Estimate
    {
        if ($this->estimateId === null || ! hash_equals($this->savedInputHash, $this->inputHash())) {
            $this->addError('estimate', 'Save or discard changed inputs before reviewing or converting the saved estimate.');

            return null;
        }
        $estimate = Estimate::query()->findOrFail($this->estimateId);
        EstimateAccess::authorize($estimate, $this->user());
        $this->checkRecord($estimate);

        return $estimate;
    }

    public function discard(): void
    {
        abort_if($this->estimateId === null, 422);
        $this->redirect(route('admin.estimations.show', $this->estimateId));
    }

    public function review(): void
    {
        abort_unless($this->user()->can('estimates.manage'), 403);
        if (($estimate = $this->ready()) === null) {
            return;
        }
        $estimate = app(ReviewEstimate::class)->handle($estimate, $this->user());
        $this->status = $estimate->status->value;
        $this->savedRecordHash = $this->recordHash($estimate);
        $this->toast('Estimate '.$estimate->number.' marked reviewed.', 'success');
    }

    public function convert(): void
    {
        abort_unless($this->user()->can('estimates.manage') && $this->user()->can('quotes.manage'), 403);
        if (($estimate = $this->ready()) === null) {
            return;
        }
        $quote = app(ConvertEstimateToQuote::class)->handle($estimate, $this->user());
        $this->status = EstimateStatus::Converted->value;
        $this->redirect(route('admin.quotes.show', $quote));
    }

    private function recordHash(Estimate $estimate): string
    {
        return hash('sha256', json_encode($estimate->getRawOriginal(), JSON_THROW_ON_ERROR));
    }

    private function checkRecord(Estimate $estimate): void
    {
        if (! hash_equals($this->savedRecordHash, $this->recordHash($estimate))) {
            throw ValidationException::withMessages(['estimate' => 'This estimate changed in another session. Reload before saving, reviewing or converting.']);
        }
    }

    private function inputHash(): string
    {
        return hash('sha256', json_encode($this->payload(), JSON_THROW_ON_ERROR));
    }

    /** @return array{title: string, service_key: string, contact_id?: int|null, quantity: string, finish: string, contingency_bp: int|string, assumptions?: string|null, exclusions?: string|null, catalog_lines: list<array{catalog_item_id: int, qty: string}>} */
    private function payload(): array
    {
        return [
            'title' => $this->title,
            'service_key' => $this->serviceKey,
            'contact_id' => $this->contactId !== '' ? (int) $this->contactId : null,
            'lead_id' => $this->leadId,
            'quantity' => $this->quantity,
            'finish' => $this->finish,
            'contingency_bp' => $this->contingencyBp,
            'assumptions' => $this->assumptions !== '' ? $this->assumptions : null,
            'exclusions' => $this->exclusions !== '' ? $this->exclusions : null,
            'catalog_lines' => $this->normalizedLines(),
        ];
    }

    /** @return list<array{catalog_item_id: int, qty: string}> */
    private function normalizedLines(): array
    {
        return collect($this->catalogLines)
            ->map(static fn (array $line): array => ['catalog_item_id' => (int) $line['catalog_item_id'], 'qty' => (string) $line['qty']])
            ->values()
            ->all();
    }

    private function currentRate(): ?EstimatorRate
    {
        return $this->serviceKey !== ''
            ? EstimatorRate::query()->where('key', $this->serviceKey)->where('is_active', true)->first()
            : null;
    }
}
