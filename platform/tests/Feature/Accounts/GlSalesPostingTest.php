<?php

use App\Domain\Accounts\Actions\PostInvoiceToLedger;
use App\Domain\Accounts\Actions\PostPaymentToLedger;
use App\Domain\Accounts\Exceptions\JournalPostingFailed;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\JournalEntry;
use App\Domain\Accounts\Models\PaymentMethodAccount;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\RecalculateQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Actions\VerifyPayment;
use App\Domain\Sales\Enums\MilestonePlanKey;
use App\Domain\Sales\Models\BankAccount;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuoteLinks;
use App\Livewire\Admin\Accounts\MethodAccounts;
use App\Settings\SalesSettings;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| P8.2 (docs/18 batch D): the sales flow feeds the ledger. An issued invoice books the receivable and
| revenue by business line; a verified receipt books the method's cash account against receivables
| (or client credit); both are idempotent per source; an unmapped method is a hard stop, not a guess.
*/

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();

    $this->owner = $this->userWithRole('owner');
    $this->accounts = $this->userWithRole('accountant');
    $this->sales = $this->userWithRole('sales');

    $this->lead = Lead::factory()->create(['owner_id' => $this->sales->id, 'business_line' => 'interior', 'city' => 'Lahore', 'area' => 'Model Town']);

    BankAccount::query()->create(['business_line' => BusinessLine::Interior, 'title' => 'WOODEX INTERIOR', 'bank_name' => 'Bank Alfalah', 'account_number' => '0000-0000-0000', 'iban' => 'PK00ALFA0000000000000000', 'is_default' => true, 'is_active' => true]);
});

function journalFor(string $sourceType, int $sourceId): JournalEntry
{
    return JournalEntry::query()
        ->with('lines.account')
        ->where('source_type', $sourceType)
        ->where('source_id', $sourceId)
        ->sole();
}

/** A sent, accepted quotation whose issued advance invoice is already booked. */
function bookedInvoice(object $test, int $rupees = 1_000_000, int $taxBp = 0): array
{
    $quote = app(CreateQuote::class)->handle($test->lead->contact, BusinessLine::Interior, $test->sales, $test->lead, null, 'Lounge and kitchen');
    $editor = app(QuoteEditor::class);
    $section = $quote->sections()->first() ?? $editor->addSection($quote, 'Lounge');
    $editor->addLine($section, ['description' => 'Wall panelling, walnut veneer', 'unit' => 'sqft', 'qty' => '1', 'rate_minor' => $rupees * 100]);
    $quote->items()->where('rate_minor', 0)->each(fn ($item) => $editor->deleteLine($item));
    $editor->setMilestonePlan($quote->refresh(), MilestonePlanKey::Execution);

    if ($taxBp > 0) {
        $quote->forceFill(['tax_bp' => $taxBp])->save();
    }

    app(RecalculateQuote::class)->handle($quote->refresh(), $test->sales);
    $sent = app(SendQuote::class)->handle($quote->refresh(), $test->sales)->quote->refresh();

    $test->post(QuoteLinks::signed('quotes.portal.accept', $sent), ['revision' => $sent->revision, 'name' => 'Ayesha Khan', 'confirm' => '1']);

    $invoice = Invoice::query()->where('quote_id', $sent->id)->sole();

    return [$sent, $invoice->refresh()];
}

it('books an issued invoice: receivable against revenue by business line', function () {
    [, $invoice] = bookedInvoice($this);

    $entry = journalFor('invoice', $invoice->id);

    $debitLine = $entry->lines->where('debit_minor', '>', 0)->sole();
    $revenueLine = $entry->lines->where('credit_minor', '>', 0)->where('account_id', Account::query()->where('code', '4010')->value('id'))->sole();

    expect($debitLine->account->code)->toBe('1100')
        ->and((int) $debitLine->debit_minor)->toBe((int) $invoice->total_minor)
        ->and($revenueLine->account->code)->toBe('4010')
        ->and((int) $revenueLine->credit_minor)->toBe((int) $invoice->total_minor - (int) $invoice->tax_minor)
        ->and($entry->business_line?->value)->toBe('interior')
        ->and($entry->reference)->toBe($invoice->number);
});

it('splits sales tax to taxes payable when the quotation carries it', function () {
    [, $invoice] = bookedInvoice($this, 1_000_000, 1600);

    expect((int) $invoice->tax_minor)->toBeGreaterThan(0);

    $entry = journalFor('invoice', $invoice->id);
    $taxLine = $entry->lines->where('account_id', Account::query()->where('code', '2100')->value('id'))->first();

    expect($taxLine)->not->toBeNull()
        ->and((int) $taxLine->credit_minor)->toBe((int) $invoice->tax_minor)
        ->and($entry->lines->sum('debit_minor'))->toBe($entry->lines->sum('credit_minor'));
});

it('posts an invoice exactly once — retries return the same entry', function () {
    [, $invoice] = bookedInvoice($this);

    $again = app(PostInvoiceToLedger::class)->handle($invoice, $this->owner);

    expect(JournalEntry::query()->where('source_type', 'invoice')->count())->toBe(1)
        ->and($again->id)->toBe(JournalEntry::query()->where('source_type', 'invoice')->sole()->id);
});

it('does not book a draft invoice', function () {
    app(SalesSettings::class)->fill(['advance_invoice_mode' => 'draft'])->save();

    $quote = app(CreateQuote::class)->handle($this->lead->contact, BusinessLine::Interior, $this->sales, $this->lead, null, 'Draft job');
    $editor = app(QuoteEditor::class);
    $section = $quote->sections()->first() ?? $editor->addSection($quote, 'Lounge');
    $editor->addLine($section, ['description' => 'Wall panelling, walnut veneer', 'unit' => 'sqft', 'qty' => '1', 'rate_minor' => 500_000 * 100]);
    $quote->items()->where('rate_minor', 0)->each(fn ($item) => $editor->deleteLine($item));
    $editor->setMilestonePlan($quote->refresh(), MilestonePlanKey::Execution);
    app(RecalculateQuote::class)->handle($quote->refresh(), $this->sales);
    $sent = app(SendQuote::class)->handle($quote->refresh(), $this->sales)->quote->refresh();
    $this->post(QuoteLinks::signed('quotes.portal.accept', $sent), ['revision' => $sent->revision, 'name' => 'Ayesha Khan', 'confirm' => '1']);

    $invoice = Invoice::query()->where('quote_id', $sent->id)->sole();

    expect($invoice->status->value)->toBe('draft')
        ->and(JournalEntry::query()->where('source_type', 'invoice')->count())->toBe(0);
});

it('refuses to book an invoice when the chart lost its receivable account', function () {
    [, $invoice] = bookedInvoice($this);

    JournalEntry::query()->where('source_type', 'invoice')->delete();
    Account::query()->where('code', '1100')->delete();

    app(PostInvoiceToLedger::class)->handle($invoice, $this->owner);
})->throws(JournalPostingFailed::class, '1100');

it('books a verified receipt: the method account against receivables', function () {
    [, $invoice] = bookedInvoice($this);

    $payment = Payment::query()->create([
        'contact_id' => $invoice->contact_id,
        'invoice_id' => $invoice->id,
        'project_id' => $invoice->project_id,
        'amount_minor' => 400_000_00,
        'received_on' => today()->toDateString(),
        'method' => 'cash',
        'status' => 'pending_verification',
        'created_by' => $this->accounts->id,
    ]);

    $verified = app(VerifyPayment::class)->handle($payment, $this->accounts);

    expect($verified->status->value)->toBe('verified')
        ->and($verified->receipt_number)->not->toBeNull();

    $entry = journalFor('payment', $payment->id);
    $debit = $entry->lines->where('debit_minor', '>', 0)->sole();

    expect($debit->account->code)->toBe('1010') // seeded default: cash → Cash in hand
        ->and((int) $debit->debit_minor)->toBe(400_000_00)
        ->and($entry->lines->where('credit_minor', '>', 0)->sole()->account->code)->toBe('1100')
        ->and($entry->lines->sum('debit_minor'))->toBe($entry->lines->sum('credit_minor'));
});

it('books a receipt with no invoices to settle as client credit (advances)', function () {
    $payment = Payment::query()->create([
        'contact_id' => $this->lead->contact_id,
        'amount_minor' => 50_000_00,
        'received_on' => today()->toDateString(),
        'method' => 'easypaisa',
        'status' => 'pending_verification',
        'created_by' => $this->accounts->id,
    ]);

    app(VerifyPayment::class)->handle($payment, $this->accounts);

    $entry = journalFor('payment', $payment->id);

    expect($entry->lines->where('debit_minor', '>', 0)->sole()->account->code)->toBe('1040') // seeded: easypaisa → Mobile wallets
        ->and($entry->lines->where('credit_minor', '>', 0)->sole()->account->code)->toBe('1150');
});

it('splits a part-settled receipt between receivables and client credit', function () {
    [, $invoice] = bookedInvoice($this, 100_000);

    // More than the invoice balance: the rest is credit, not a wrong receivable.
    $payment = Payment::query()->create([
        'contact_id' => $invoice->contact_id,
        'invoice_id' => $invoice->id,
        'amount_minor' => 150_000_00,
        'received_on' => today()->toDateString(),
        'method' => 'bank_transfer',
        'status' => 'pending_verification',
        'created_by' => $this->accounts->id,
    ]);

    app(VerifyPayment::class)->handle($payment, $this->accounts);

    $entry = journalFor('payment', $payment->id);

    $receivable = $entry->lines->where('account_id', Account::query()->where('code', '1100')->value('id'))->first();
    $credit = $entry->lines->where('account_id', Account::query()->where('code', '1150')->value('id'))->first();

    expect($receivable)->not->toBeNull()
        ->and((int) $receivable->credit_minor)->toBe((int) $invoice->total_minor)
        ->and($credit)->not->toBeNull()
        ->and((int) $credit->credit_minor)->toBe(150_000_00 - (int) $invoice->total_minor);
});

it('is a hard stop when the payment method is unmapped — nothing verifies, nothing posts', function () {
    [, $invoice] = bookedInvoice($this);

    PaymentMethodAccount::query()->where('method', 'cash')->delete();

    $payment = Payment::query()->create([
        'contact_id' => $invoice->contact_id,
        'invoice_id' => $invoice->id,
        'amount_minor' => 100_000_00,
        'received_on' => today()->toDateString(),
        'method' => 'cash',
        'status' => 'pending_verification',
        'created_by' => $this->accounts->id,
    ]);

    try {
        app(VerifyPayment::class)->handle($payment, $this->accounts);
        $this->fail('An unmapped method should have refused to verify.');
    } catch (JournalPostingFailed $exception) {
        expect($exception->getMessage())->toContain('not mapped');

        $payment->refresh();

        expect($payment->status->value)->toBe('pending_verification')
            ->and($payment->receipt_number)->toBeNull()
            ->and(JournalEntry::query()->where('source_type', 'payment')->count())->toBe(0)
            ->and($invoice->refresh()->paid_minor)->toBe(0);
    }
});

it('lets the owner remap a method and books the next receipt to the new account', function () {
    $bank = Account::query()->where('code', '1030')->first();

    Livewire::actingAs($this->owner)
        ->test(MethodAccounts::class)
        ->set('map.cash', (string) $bank->id)
        ->call('save')
        ->assertHasNoErrors();

    expect((int) PaymentMethodAccount::query()->where('method', 'cash')->value('account_id'))->toBe($bank->id);

    [, $invoice] = bookedInvoice($this, 100_000);

    $payment = Payment::query()->create([
        'contact_id' => $invoice->contact_id,
        'invoice_id' => $invoice->id,
        'amount_minor' => 100_000_00,
        'received_on' => today()->toDateString(),
        'method' => 'cash',
        'status' => 'pending_verification',
        'created_by' => $this->accounts->id,
    ]);

    app(VerifyPayment::class)->handle($payment, $this->accounts);

    $entry = journalFor('payment', $payment->id);

    expect($entry->lines->where('debit_minor', '>', 0)->sole()->account->code)->toBe('1030');
});

it('keeps the method mapping screen away from non-owners', function () {
    Livewire::actingAs($this->accounts)
        ->test(MethodAccounts::class)
        ->call('save')
        ->assertStatus(403);
});

it('posts a payment exactly once — retries return the same entry', function () {
    [, $invoice] = bookedInvoice($this, 100_000);

    $payment = Payment::query()->create([
        'contact_id' => $invoice->contact_id,
        'invoice_id' => $invoice->id,
        'amount_minor' => 100_000_00,
        'received_on' => today()->toDateString(),
        'method' => 'cash',
        'status' => 'pending_verification',
        'created_by' => $this->accounts->id,
    ]);

    $verified = app(VerifyPayment::class)->handle($payment, $this->accounts);
    $again = app(PostPaymentToLedger::class)->handle($verified, $this->accounts);

    expect(JournalEntry::query()->where('source_type', 'payment')->count())->toBe(1)
        ->and($again->id)->toBe(JournalEntry::query()->where('source_type', 'payment')->sole()->id);
});
