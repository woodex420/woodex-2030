<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Sales;

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Sales\Actions\IssueRecurringInvoice;
use App\Domain\Sales\Enums\RecurringFrequency;
use App\Domain\Sales\Models\RecurringInvoice;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Settings\SalesSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Standing charges (slice E, part 2): what bills itself, when it last did, and the one form that starts a new one.
 *
 * The screen is honest about state — a paused schedule says paused, and the count and the last date are the record
 * of what actually happened, not a promise. Every mutation needs `invoices.manage`.
 */
#[Layout('layouts::admin')]
#[Title('Recurring invoices')]
class RecurringInvoices extends Component
{
    use InteractsWithAdmin;

    public string $search = '';

    public bool $showForm = false;

    public ?int $contactId = null;

    public string $title = '';

    public string $businessLine = 'interior';

    public string $lineDescription = '';

    public string $lineQty = '1';

    public string $lineRate = '';

    public string $taxPercent = '0';

    public string $frequency = 'monthly';

    public string $startsOn = '';

    public string $dueDays = '';

    public string $notes = '';

    public function mount(SalesSettings $settings): void
    {
        $this->authorize('invoices.view');

        $this->startsOn = today()->toDateString();
        $this->dueDays = (string) $settings->invoice_due_days;
    }

    public function openForm(?int $contactId = null): void
    {
        $this->authorize('invoices.manage');

        $this->resetValidation();
        $this->contactId = $contactId;
        $this->showForm = true;
    }

    public function save(SalesSettings $settings): void
    {
        $this->authorize('invoices.manage');

        $data = $this->validate([
            'contactId' => ['required', 'integer', 'exists:contacts,id'],
            'title' => ['required', 'string', 'max:160'],
            'businessLine' => ['required', 'in:interior,furniture'],
            'lineDescription' => ['required', 'string', 'max:200'],
            'lineQty' => ['required', 'integer', 'min:1', 'max:999'],
            'lineRate' => ['required', 'numeric', 'min:0.01', 'max:100000000'],
            'taxPercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'frequency' => ['required', 'in:monthly,quarterly,yearly'],
            'startsOn' => ['required', 'date'],
            'dueDays' => ['required', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $rateMinor = (int) round((float) $data['lineRate'] * 100);

        $schedule = RecurringInvoice::query()->create([
            'contact_id' => $data['contactId'],
            'title' => $data['title'],
            'business_line' => $data['businessLine'],
            'frequency' => $data['frequency'],
            'lines' => [[
                'description' => $data['lineDescription'],
                'unit' => 'service',
                'qty' => (int) $data['lineQty'],
                'rate_minor' => $rateMinor,
                'discount_minor' => 0,
            ]],
            'tax_bp' => (int) round((float) $data['taxPercent'] * 100),
            'due_days' => (int) $data['dueDays'],
            'notes' => $data['notes'] !== '' ? $data['notes'] : null,
            'starts_on' => $data['startsOn'],
            'next_issue_on' => $data['startsOn'],
            'is_active' => true,
            'created_by' => $this->user()->id,
        ]);

        activity('billing')
            ->causedBy($this->user())
            ->performedOn($schedule)
            ->withProperties(['frequency' => $schedule->frequency->value, 'total_minor' => $schedule->totalMinor()])
            ->log('Started a recurring invoice: '.$schedule->title.' ('.$schedule->frequency->description().').');

        $this->toast($schedule->title.' will bill '.$schedule->frequency->description().'.');
        $this->closeForm();
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->reset('contactId', 'title', 'lineDescription', 'lineQty', 'lineRate', 'taxPercent', 'notes');
        $this->resetValidation();
    }

    public function toggle(int $id): void
    {
        $this->authorize('invoices.manage');

        $schedule = RecurringInvoice::query()->findOrFail($id);
        $schedule->forceFill(['is_active' => ! $schedule->is_active])->save();

        activity('billing')
            ->causedBy($this->user())
            ->performedOn($schedule)
            ->log(($schedule->is_active ? 'Resumed' : 'Paused').' the recurring invoice '.$schedule->title.'.');

        $this->toast($schedule->title.' is '.($schedule->is_active ? 'billing again' : 'paused').'.');
    }

    /** Bill the current period now, even if the schedule's own date is still ahead. */
    public function issueNow(int $id): void
    {
        $this->authorize('invoices.manage');

        $schedule = RecurringInvoice::query()->findOrFail($id);

        if (! $schedule->is_active) {
            $this->toast('This schedule is paused — resume it first.');

            return;
        }

        if ($schedule->next_issue_on->isAfter(today())) {
            $schedule->forceFill(['next_issue_on' => today()])->save();
        }

        $invoice = app(IssueRecurringInvoice::class)->handle($schedule, $this->user());

        $this->toast($invoice === null
            ? 'Nothing to bill on this schedule right now.'
            : 'Raised '.$invoice->number.' for '.$schedule->contact->name.'.');
    }

    public function delete(int $id): void
    {
        $this->authorize('invoices.manage');

        $schedule = RecurringInvoice::query()->findOrFail($id);

        activity('billing')
            ->causedBy($this->user())
            ->withProperties(['contact' => $schedule->contact->name, 'invoices' => $schedule->invoice_count])
            ->log('Deleted the recurring invoice '.$schedule->title.'.');

        $schedule->delete();

        $this->toast('Recurring schedule deleted. The invoices it raised stay.');
    }

    public function render(SalesSettings $settings): View
    {
        $schedules = RecurringInvoice::query()
            ->with('contact')
            ->when(trim($this->search) !== '', fn ($query) => $query->where(function ($inner): void {
                $term = '%'.trim($this->search).'%';
                $inner->where('title', 'like', $term)
                    ->orWhereHas('contact', fn ($contact) => $contact->where('name', 'like', $term));
            }))
            ->orderByDesc('is_active')
            ->orderBy('next_issue_on')
            ->get();

        /** @var Collection<int, Contact> $contacts */
        $contacts = Contact::query()
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name']);

        return view('livewire.admin.sales.recurring-invoices', [
            'schedules' => $schedules,
            'contacts' => $contacts,
            'lines' => BusinessLine::options(),
            'frequencies' => RecurringFrequency::options(),
            'lakh' => $settings->lakh_grouping,
        ]);
    }
}
