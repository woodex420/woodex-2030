<?php

use App\Domain\Accounts\Models\AccountingPeriod;
use App\Domain\Accounts\Models\JournalEntry;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\AcceptQuote;
use App\Domain\Sales\Actions\CreateManualInvoice;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Enums\InvoiceKind;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Exceptions\QuoteActionDenied;
use App\Domain\Sales\Models\BankAccount;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Services\QuoteEditor;
use App\Livewire\Admin\Sales\InvoiceCreate;
use App\Livewire\Admin\Sales\InvoiceShow;
use App\Livewire\Admin\Sales\InvoicesIndex;
use App\Livewire\Admin\Sales\InvoiceTrackingBoard;
use App\Settings\SalesSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    $this->owner = $this->userWithRole('owner');
    $this->actingAs($this->owner);
    $this->contact = Contact::factory()->create();
    BankAccount::query()->create(['business_line' => BusinessLine::Interior, 'title' => 'WOODEX INTERIOR', 'bank_name' => 'Bank Alfalah', 'account_number' => '0000-0000-0000', 'iban' => 'PK00ALFA0000000000000000', 'is_default' => true, 'is_active' => true]);
    BankAccount::query()->create(['business_line' => BusinessLine::Furniture, 'title' => 'WOODEX FURNITURE', 'bank_name' => 'Meezan Bank', 'account_number' => '1111-1111-1111', 'iban' => 'PK11MEZN0000000000000000', 'is_default' => true, 'is_active' => true]);
    // Lines: 2 × Rs 15,000 + 1.5 × Rs 4,000 = Rs 36,000; less Rs 1,000 discount; 5% tax on Rs 35,000 = Rs 1,750.
    $this->input = [
        'contact_id' => $this->contact->id,
        'business_line' => 'interior',
        'issue_date' => '2026-10-20',
        'due_date' => '2026-10-27',
        'discount_minor' => 100000,
        'tax_bp' => 500,
        'items' => [
            ['description' => 'Wardrobe door repair', 'unit' => 'job', 'qty' => '2', 'rate_minor' => 1500000],
            ['description' => 'French polish', 'unit' => 'job', 'qty' => '1.5', 'rate_minor' => 400000],
        ],
    ];
});

it('creates a manual draft in its own MI series with exact totals', function () {
    $invoice = app(CreateManualInvoice::class)->handle($this->owner, $this->input);

    expect($invoice->number)->toBe('MI-'.now()->format('Y').'-0001')
        ->and($invoice->kind)->toBe(InvoiceKind::Manual)
        ->and($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->company_id)->toBe($this->contact->company_id)
        ->and($invoice->subtotal_minor)->toBe(3600000)
        ->and($invoice->discount_minor)->toBe(100000)
        ->and($invoice->tax_minor)->toBe(175000)
        ->and($invoice->total_minor)->toBe(3675000)
        ->and($invoice->balance_minor)->toBe(3675000)
        ->and($invoice->snapshot)->toBeNull()
        ->and($invoice->items)->toHaveCount(2)
        ->and((int) $invoice->items[0]->line_total_minor)->toBe(3000000)
        ->and((int) $invoice->items[1]->line_total_minor)->toBe(600000)
        ->and((int) $invoice->items[0]->tax_bp)->toBe(500)
        ->and(JournalEntry::query()->where('source_type', 'invoice')->where('source_id', $invoice->id)->exists())->toBeFalse();

    $second = app(CreateManualInvoice::class)->handle($this->owner, $this->input);
    expect($second->number)->toBe('MI-'.now()->format('Y').'-0002');
});

it('issues at creation: frozen document and a balanced ledger entry', function () {
    $invoice = app(CreateManualInvoice::class)->handle($this->owner, [...$this->input, 'issue' => true]);

    expect($invoice->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->snapshot)->not->toBeNull()
        ->and($invoice->snapshot['number'])->toBe($invoice->number)
        ->and($invoice->snapshot['bank'])->not->toBeEmpty();

    $bytes = Storage::disk('local')->get($invoice->pdf_path);
    expect($bytes)->toStartWith('%PDF-')
        ->and($invoice->pdf_sha256)->toBe(hash('sha256', $bytes));

    $journal = JournalEntry::query()->with('lines.account')->where('source_type', 'invoice')->where('source_id', $invoice->id)->firstOrFail();
    $byCode = $journal->lines->keyBy(fn ($line) => $line->account->code);
    expect((int) $byCode['1100']->debit_minor)->toBe(3675000)
        ->and((int) $byCode['4010']->credit_minor)->toBe(3500000)
        ->and((int) $byCode['2100']->credit_minor)->toBe(175000);

    $this->get(route('admin.invoices.pdf', $invoice))->assertOk()->assertStreamedContent($bytes);
});

it('issues a saved draft later and refuses a second issue', function () {
    $draft = app(CreateManualInvoice::class)->handle($this->owner, $this->input);

    $issued = app(IssueInvoice::class)->handle($draft, $this->owner);

    expect($issued->status)->toBe(InvoiceStatus::Issued)
        ->and($issued->snapshot)->not->toBeNull()
        ->and($issued->issue_date->format('Y-m-d'))->toBe('2026-10-20')
        ->and(JournalEntry::query()->where('source_type', 'invoice')->where('source_id', $draft->id)->exists())->toBeTrue();

    // The owner passes every gate, so the refusal that matters is the action's own status guard.
    app(IssueInvoice::class)->handle($issued, $this->owner);
})->throws(QuoteActionDenied::class);

it('refuses to issue a zero-amount draft', function () {
    $draft = app(CreateManualInvoice::class)->handle($this->owner, $this->input);
    $draft->forceFill(['total_minor' => 0])->save();

    app(IssueInvoice::class)->handle($draft->refresh(), $this->owner);
})->throws(QuoteActionDenied::class);

it('issues a draft advance invoice — the door draft mode never had', function () {
    $sales = app(SalesSettings::class);
    $sales->advance_invoice_mode = 'draft';
    $sales->save();

    $lead = Lead::factory()->create(['owner_id' => $this->owner->id]);
    $quote = app(CreateQuote::class)->handle($lead->contact, BusinessLine::Interior, $this->owner, $lead);
    app(QuoteEditor::class)->updateLine($quote->items()->firstOrFail(), ['description' => 'Reviewed joinery', 'rate_minor' => 100000]);
    app(SendQuote::class)->handle($quote->refresh(), $this->owner);
    app(AcceptQuote::class)->handle($quote->refresh(), 'Preview Client', null, '127.0.0.1', 'test');

    $invoice = Invoice::query()->where('quote_id', $quote->id)->firstOrFail();
    expect($invoice->status)->toBe(InvoiceStatus::Draft)->and($invoice->snapshot)->toBeNull();

    app(IssueInvoice::class)->handle($invoice, $this->owner);

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->snapshot)->not->toBeNull()
        ->and($invoice->issue_date)->not->toBeNull()
        ->and(JournalEntry::query()->where('source_type', 'invoice')->where('source_id', $invoice->id)->exists())->toBeTrue();
});

it('refuses invalid manual invoice input', function (array $override) {
    app(CreateManualInvoice::class)->handle($this->owner, [...$this->input, ...$override]);
})->with([
    'no lines' => [['items' => []]],
    'zero quantity' => [['items' => [['description' => 'X', 'qty' => '0', 'rate_minor' => 100]]]],
    'word quantity' => [['items' => [['description' => 'X', 'qty' => 'two', 'rate_minor' => 100]]]],
    'blank description' => [['items' => [['description' => '  ', 'qty' => '1', 'rate_minor' => 100]]]],
    'negative rate' => [['items' => [['description' => 'X', 'qty' => '1', 'rate_minor' => -5]]]],
    'all zero rates' => [['items' => [['description' => 'X', 'qty' => '1', 'rate_minor' => 0]], 'discount_minor' => 0, 'tax_bp' => 0]],
    'discount over subtotal' => [['discount_minor' => 3600001]],
    'due before issue' => [['due_date' => '2026-10-19']],
    'unknown client' => [['contact_id' => 999999]],
    'unknown bank' => [['bank_account_id' => 999999]],
])->throws(ValidationException::class);

it('keeps manual invoices behind invoices.manage', function () {
    $draft = app(CreateManualInvoice::class)->handle($this->owner, $this->input);
    $sales = $this->userWithRole('sales');

    expect($sales->can('invoices.view'))->toBeTrue()->and($sales->can('invoices.manage'))->toBeFalse();

    $this->actingAs($sales)->get(route('admin.invoices.create'))->assertForbidden();
    Livewire::actingAs($sales)->test(InvoiceShow::class, ['invoice' => $draft])->assertViewHas('canIssue', false);

    // Even the owner (Gate::before) sees no Issue button once the invoice is issued — state first.
    $issued = app(IssueInvoice::class)->handle($draft, $this->owner);
    Livewire::actingAs($this->owner)->test(InvoiceShow::class, ['invoice' => $issued])->assertViewHas('canIssue', false);

    $this->actingAs($this->userWithRole('accountant'))->get(route('admin.invoices.create'))->assertOk();
});

it('refuses the create action for a view-only user', function () {
    app(CreateManualInvoice::class)->handle($this->userWithRole('sales'), $this->input);
})->throws(AuthorizationException::class);

it('saves a draft from the New invoice screen', function () {
    $component = Livewire::test(InvoiceCreate::class)
        ->call('selectContact', $this->contact->id)
        ->set('items.0.description', 'Repair visit')
        ->set('items.0.qty', '2')
        ->set('items.0.rate', '15000')
        ->set('taxPercent', '5')
        ->call('saveDraft')
        ->assertHasNoErrors();

    $invoice = Invoice::query()->where('kind', InvoiceKind::Manual->value)->latest('id')->firstOrFail();

    expect($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->total_minor)->toBe(3150000);

    $component->assertRedirect(route('admin.invoices.show', $invoice));
});

it('issues from the New invoice screen', function () {
    Livewire::test(InvoiceCreate::class)
        ->call('selectContact', $this->contact->id)
        ->set('items.0.description', 'Design fee')
        ->set('items.0.rate', '50000')
        ->call('saveAndIssue')
        ->assertHasNoErrors();

    $invoice = Invoice::query()->where('kind', InvoiceKind::Manual->value)->latest('id')->firstOrFail();

    expect($invoice->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->snapshot)->not->toBeNull()
        ->and(JournalEntry::query()->where('source_type', 'invoice')->where('source_id', $invoice->id)->exists())->toBeTrue();
});

it('explains instead of erroring when the issue date has no accounting period', function () {
    Livewire::test(InvoiceCreate::class)
        ->call('selectContact', $this->contact->id)
        ->set('issueDate', '2025-06-01')
        ->set('dueDate', '2025-06-08')
        ->set('items.0.description', 'Old repair')
        ->set('items.0.rate', '5000')
        ->call('saveAndIssue')
        ->assertNoRedirect();

    expect(Invoice::query()->where('kind', InvoiceKind::Manual->value)->count())->toBe(0);
});

it('filters the tracking board by year and line', function () {
    AccountingPeriod::query()->create(['name' => 'Calendar 2025 (test)', 'starts_on' => '2025-01-01', 'ends_on' => '2025-12-31']);

    // Due yesterday, so both sit in the board's default Overdue group.
    $interior = app(CreateManualInvoice::class)->handle($this->owner, [...$this->input, 'due_date' => '2026-10-25', 'issue' => true]);
    $furniture = app(CreateManualInvoice::class)->handle($this->owner, [...$this->input,
        'business_line' => 'furniture', 'issue_date' => '2025-06-01', 'due_date' => '2025-06-08', 'issue' => true,
    ]);

    Livewire::test(InvoiceTrackingBoard::class)
        ->assertSee($interior->number)
        ->assertSee($furniture->number)
        ->set('year', '2025')
        ->assertSee($furniture->number)
        ->assertDontSee($interior->number)
        ->set('year', '')
        ->set('line', 'interior')
        ->assertSee($interior->number)
        ->assertDontSee($furniture->number);
});

it('exports invoices to CSV from the board and the list', function () {
    app(CreateManualInvoice::class)->handle($this->owner, [...$this->input, 'issue' => true]);
    $file = 'invoices-'.now()->format('Y-m-d').'.csv';

    Livewire::test(InvoiceTrackingBoard::class)->call('export')->assertFileDownloaded($file);
    Livewire::test(InvoicesIndex::class)->call('export')->assertFileDownloaded($file);
    Livewire::test(InvoicesIndex::class)->set('status', 'draft')->call('export')->assertFileDownloaded($file);
});
