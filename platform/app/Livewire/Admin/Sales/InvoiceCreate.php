<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Sales;

use App\Domain\Accounts\Exceptions\JournalPostingFailed;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Sales\Actions\CreateManualInvoice;
use App\Domain\Sales\Enums\InvoiceKind;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Enums\Unit;
use App\Domain\Sales\Models\BankAccount;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\InvoiceItem;
use App\Domain\Sales\Services\DocumentStyles;
use App\Domain\Sales\Services\FurnitureDocument;
use App\Domain\Sales\Services\InvoicePdf;
use App\Domain\Sales\Services\InvoiceSnapshot;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Settings\SalesSettings;
use App\Support\NumberInput;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * New invoice (Slice P): bill work that has no quotation — pick the client, write the lines, then save a draft
 * or issue in the same act (frozen + booked via IssueInvoice, like every other invoice).
 */
#[Layout('layouts::admin')]
#[Title('New invoice')]
class InvoiceCreate extends Component
{
    use InteractsWithAdmin;

    #[Url(as: 'contact', except: 0)]
    public int $contactId = 0;

    public string $contactSearch = '';

    public string $line = 'interior';

    public string $issueDate = '';

    public string $dueDate = '';

    public string $bankAccountId = '';

    /** @var list<array{description: string, qty: string, unit: string, rate: string}> */
    public array $items = [['description' => '', 'qty' => '1', 'unit' => 'job', 'rate' => '']];

    public string $rent = '';

    public string $shipping = '';

    public string $advance = '50';

    /** @var array<string, string> */
    public array $furniture = ['document_title' => '', 'delivery_text' => '', 'transport_text' => 'Outstation charges depend on city, quantity and load.', 'tax_note' => '', 'po_reference' => ''];

    public string $discount = '';

    public string $taxPercent = '';

    public string $notes = '';

    public string $terms = '';

    /** Live preview drawer: the invoice document as it will print, from the form as typed. */
    public bool $showPreview = true;

    public function mount(): void
    {
        $this->authorize('create', Invoice::class);

        $sales = app(SalesSettings::class);
        $this->furniture = FurnitureDocument::text(['signatories_text' => FurnitureDocument::signatureText($sales->signatories)]);
        $this->issueDate = today()->toDateString();
        $this->dueDate = today()->addDays((int) $sales->invoice_due_days)->toDateString();
        $this->terms = (string) ($sales->invoice_terms ?? '');

        if ($this->contactId > 0 && $this->visibleContact($this->contactId) === null) {
            $this->contactId = 0;
        }
    }

    public function updatedLine(): void
    {
        $this->bankAccountId = '';
        if ($this->line === 'furniture') {
            foreach ($this->items as &$item) {
                if (empty($item['description']) && $item['unit'] === 'job') {
                    $item['unit'] = 'nos';
                }
            }
        }
    }

    public function updated(): void
    {
        $this->touchPreview();
    }

    public function selectContact(int $contactId): void
    {
        if ($this->visibleContact($contactId) === null) {
            return;
        }

        $this->contactId = $contactId;
        $this->contactSearch = '';
        $this->resetValidation('contactId');
    }

    public function clearContact(): void
    {
        $this->contactId = 0;
    }

    public function addItem(): void
    {
        if (count($this->items) < 50) {
            $this->items[] = ['description' => '', 'qty' => '1', 'unit' => 'job', 'rate' => ''];
        }
    }

    public function removeItem(int $index): void
    {
        if (count($this->items) > 1 && isset($this->items[$index])) {
            unset($this->items[$index]);
            $this->items = array_values($this->items);
        }
    }

    public function saveDraft(): void
    {
        $this->store(false);
    }

    public function saveAndIssue(): void
    {
        $this->store(true);
    }

    private function store(bool $issue): void
    {
        $this->authorize('create', Invoice::class);

        $this->validate([
            'contactId' => ['required', 'integer', 'min:1'],
            'line' => ['required', Rule::in(['interior', 'furniture'])],
            'issueDate' => ['required', 'date'],
            'dueDate' => ['required', 'date', 'after_or_equal:issueDate'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_name' => ['nullable', 'string', 'max:160'],
            'items.*.description' => ['required', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:10000'],
        ], [], ['contactId' => 'client', 'issueDate' => 'invoice date', 'dueDate' => 'due date', 'items.*.description' => 'description']);

        if ($this->visibleContact($this->contactId) === null) {
            $this->addError('contactId', 'Pick a client you can see.');

            return;
        }

        $items = [];
        $ok = true;

        foreach ($this->items as $i => $row) {
            try {
                $qty = NumberInput::quantity(trim((string) $row['qty']));

                if ((float) $qty <= 0) {
                    throw new InvalidArgumentException;
                }
            } catch (InvalidArgumentException) {
                $this->addError("items.{$i}.qty", 'Quantity like 2 or 2.5.');
                $ok = false;
                $qty = '0';
            }

            try {
                $rate = trim((string) $row['rate']) === '' ? 0 : NumberInput::rupees(trim((string) $row['rate']));
            } catch (InvalidArgumentException) {
                $this->addError("items.{$i}.rate", 'Rate in rupees, e.g. 25000.');
                $ok = false;
                $rate = 0;
            }

            $items[] = ['product_name' => (string) ($row['product_name'] ?? ''), 'description' => (string) $row['description'], 'unit' => (string) ($row['unit'] ?? 'job'), 'qty' => $qty, 'rate_minor' => $rate];
        }

        try {
            $discount = trim($this->discount) === '' ? 0 : NumberInput::rupees(trim($this->discount));
        } catch (InvalidArgumentException) {
            $this->addError('discount', 'Discount in rupees.');
            $ok = false;
            $discount = 0;
        }

        try {
            $taxBp = trim($this->taxPercent) === '' ? 0 : NumberInput::percent(trim($this->taxPercent));
        } catch (InvalidArgumentException) {
            $this->addError('taxPercent', 'Tax percent, e.g. 5.');
            $ok = false;
            $taxBp = 0;
        }

        $extra = [];
        foreach (['rent', 'shipping', 'advance'] as $field) {
            try {
                $extra[$field] = trim($this->{$field}) === '' ? 0 : ($field === 'advance' ? NumberInput::percent($this->{$field}) : NumberInput::rupees($this->{$field}));
            } catch (InvalidArgumentException) {
                $this->addError($field, 'Enter a valid non-negative amount or percentage.');
                $ok = false;
            }
        }

        if (! $ok) {
            return;
        }

        try {
            $invoice = app(CreateManualInvoice::class)->handle($this->user(), [
                'contact_id' => $this->contactId,
                'business_line' => $this->line,
                'issue_date' => $this->issueDate,
                'due_date' => $this->dueDate,
                'bank_account_id' => $this->bankAccountId !== '' ? (int) $this->bankAccountId : null,
                'rent_minor' => $extra['rent'], 'shipping_minor' => $extra['shipping'], 'advance_bp' => $extra['advance'], 'furniture' => $this->furniture,
                'discount_minor' => $discount,
                'tax_bp' => $taxBp,
                'notes' => $this->notes,
                'terms' => $this->terms,
                'items' => $items,
                'issue' => $issue,
            ]);
        } catch (ValidationException $exception) {
            $this->toastValidation($exception);

            return;
        } catch (JournalPostingFailed $exception) {
            $this->toast($exception->getMessage().' Nothing was saved — save a draft, or fix the date or the period first.', 'error');

            return;
        }

        session()->flash('status', $issue
            ? "Invoice {$invoice->number} issued — frozen and booked in the ledger."
            : "Invoice {$invoice->number} saved as a draft. Issue it when you are ready to send.");

        $this->redirect(route('admin.invoices.show', $invoice), navigate: true);
    }

    /**
     * The invoice as typed so far, unsaved — exists only so the preview snapshot can be built by the
     * same code the printed invoice uses. Lenient like totals(): a half-typed row previews with a
     * zero amount; saving validates it strictly.
     */
    private function previewInvoice(): Invoice
    {
        $totals = $this->totals();
        $contact = $this->contactId > 0 ? $this->visibleContact($this->contactId) : null;

        $bankAccount = BankAccount::query()->forLine(BusinessLine::tryFrom($this->line) ?? BusinessLine::Interior)
            ->when($this->bankAccountId !== '', fn ($query) => $query->whereKey((int) $this->bankAccountId))->first();

        $invoice = new Invoice;
        $invoice->forceFill([
            'number' => 'Draft',
            'kind' => InvoiceKind::Manual,
            'status' => InvoiceStatus::Draft,
            'business_line' => BusinessLine::tryFrom($this->line) ?? BusinessLine::Interior,
            'currency' => 'PKR',
            'issue_date' => $this->issueDate !== '' ? $this->issueDate : today()->toDateString(),
            'due_date' => $this->dueDate !== '' ? $this->dueDate : today()->toDateString(),
            'bank_account_id' => $bankAccount?->id,
            'notes' => $this->notes !== '' ? $this->notes : null,
            'terms' => $this->terms !== '' ? $this->terms : null,
            'subtotal_minor' => $totals['subtotal'],
            'discount_minor' => $totals['discount'],
            'rent_minor' => $this->line === 'furniture' ? $this->lenientAmount($this->rent) : 0,
            'shipping_minor' => $this->line === 'furniture' ? $this->lenientAmount($this->shipping) : 0,
            'advance_bp' => $this->lenientAmount($this->advance, true),
            'commercial' => $this->line === 'furniture' ? ['renderer' => 'furniture-v1', 'furniture' => FurnitureDocument::text($this->furniture), 'document_design' => app(DocumentStyles::class)->copy(BusinessLine::Furniture)] : null,
            'tax_minor' => $totals['tax'],
            'total_minor' => $totals['total'],
            'paid_minor' => 0,
            'balance_minor' => $totals['total'],
        ]);

        $invoice->setRelation('contact', $contact ?? (new Contact)->forceFill(['name' => 'Select a client']));
        $invoice->setRelation('company', $contact?->company);
        $invoice->setRelation('bankAccount', $bankAccount);
        $invoice->setRelation('project', null);
        $invoice->setRelation('quote', null);
        $invoice->setRelation('payments', collect());
        $invoice->setRelation('items', collect($this->previewItems()));

        return $invoice;
    }

    /** @return list<InvoiceItem> */
    private function previewItems(): array
    {
        $taxBp = $this->lenientTaxBp();
        $items = [];

        foreach ($this->items as $i => $row) {
            try {
                $qty = (float) NumberInput::quantity(trim((string) $row['qty']));
            } catch (InvalidArgumentException) {
                $qty = 0.0;
            }

            try {
                $rate = trim((string) $row['rate']) === '' ? 0 : NumberInput::rupees(trim((string) $row['rate']));
            } catch (InvalidArgumentException) {
                $rate = 0;
            }

            $items[] = (new InvoiceItem)->forceFill([
                'product_name' => (string) ($row['product_name'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'unit' => (string) ($row['unit'] ?? 'job'),
                'qty' => $qty,
                'rate_minor' => $rate,
                'discount_minor' => 0,
                'tax_bp' => $taxBp,
                'line_total_minor' => (int) round($qty * $rate),
                'position' => $i + 1,
            ]);
        }

        return $items;
    }

    private function lenientAmount(string $value, bool $percent = false): int
    {
        try {
            return trim($value) === '' ? 0 : ($percent ? NumberInput::percent($value) : NumberInput::rupees($value));
        } catch (InvalidArgumentException) {
            return 0;
        }
    }

    private function lenientTaxBp(): int
    {
        try {
            return trim($this->taxPercent) === '' ? 0 : NumberInput::percent(trim($this->taxPercent));
        } catch (InvalidArgumentException) {
            return 0;
        }
    }

    /** The live pane's document: the same snapshot HTML the printed invoice is rendered from. */
    private function previewHtml(): string
    {
        return app(InvoicePdf::class)->html(app(InvoiceSnapshot::class)->build($this->previewInvoice()));
    }

    /** Asks the open preview pane to redraw (debounced in the browser); it renders from the form as typed. */
    private function touchPreview(): void
    {
        if ($this->showPreview) {
            $this->dispatch('preview-refresh', html: $this->previewHtml());
        }
    }

    /**
     * Lenient totals for the on-screen preview only — the action recomputes strictly.
     *
     * @return array{subtotal: int, discount: int, tax: int, total: int}
     */
    private function totals(): array
    {
        $subtotal = 0;

        foreach ($this->items as $row) {
            try {
                $qty = (float) NumberInput::quantity(trim((string) $row['qty']));
                $rate = trim((string) $row['rate']) === '' ? 0 : NumberInput::rupees(trim((string) $row['rate']));
                $subtotal += (int) round($qty * $rate);
            } catch (InvalidArgumentException) {
                // The preview ignores a half-typed row; saving validates it.
            }
        }

        try {
            $discount = trim($this->discount) === '' ? 0 : NumberInput::rupees(trim($this->discount));
        } catch (InvalidArgumentException) {
            $discount = 0;
        }

        try {
            $taxBp = trim($this->taxPercent) === '' ? 0 : NumberInput::percent(trim($this->taxPercent));
        } catch (InvalidArgumentException) {
            $taxBp = 0;
        }

        $discount = min($discount, $subtotal);
        $charges = $this->line === 'furniture' ? $this->lenientAmount($this->rent) + $this->lenientAmount($this->shipping) : 0;
        $tax = (int) round(($subtotal - $discount + $charges) * $taxBp / 10000);

        return ['subtotal' => $subtotal, 'discount' => $discount, 'tax' => $tax, 'total' => $subtotal - $discount + $charges + $tax];
    }

    private function visibleContact(int $contactId): ?Contact
    {
        /** @var Contact|null $contact */
        $contact = Contact::query()->visibleTo($this->user())->find($contactId);

        return $contact;
    }

    public function render(): View
    {
        $line = BusinessLine::tryFrom($this->line) ?? BusinessLine::Interior;

        $results = collect();

        if ($this->contactId === 0 && trim($this->contactSearch) !== '') {
            $term = '%'.trim($this->contactSearch).'%';
            $results = Contact::query()->visibleTo($this->user())
                ->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('phone', 'like', $term))
                ->orderBy('name')->limit(8)->get(['id', 'name', 'phone']);
        }

        return view('livewire.admin.sales.invoice-create', [
            'selectedContact' => $this->contactId > 0 ? $this->visibleContact($this->contactId) : null,
            'contactResults' => $results,
            'lines' => ['interior' => 'Interior (WI)', 'furniture' => 'Woodex Furniture (WFI)'],
            'units' => Unit::options(),
            'bankAccounts' => BankAccount::query()->forLine($line)->get(),
            'totals' => $this->totals(),
            'previewHtml' => $this->showPreview ? $this->previewHtml() : null,
            'lakh' => app(SalesSettings::class)->lakh_grouping,
        ]);
    }
}
