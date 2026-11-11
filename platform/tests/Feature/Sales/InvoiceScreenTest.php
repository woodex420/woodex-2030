<?php

declare(strict_types=1);

use App\Domain\Accounts\Models\JournalEntry;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\RecalculateQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuoteLinks;
use App\Livewire\Admin\Sales\InvoiceShow;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| CRM / Sales deep QA, part 1: an invoice finally has a screen of its own.
|
| Before this, /admin/invoices was a table whose only action was a PDF link: no way to see the lines, no way to
| collect the money, no way to send the client's link, and the invoices.void permission had no caller anywhere.
| These tests hold the new screen to the same promises as the rest of the money side: real rows, real ledger,
| and a void that reverses rather than deletes.
*/

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();

    $this->owner = $this->userWithRole('owner');
    $this->sales = $this->userWithRole('sales');
    $this->support = $this->userWithRole('support');

    $this->lead = Lead::factory()->create([
        'owner_id' => $this->sales->id,
        'business_line' => 'interior',
        'city' => 'Lahore',
        'area' => 'Model Town',
    ]);
});

function screenInvoice(object $test, int $rupees = 100_000): Invoice
{
    $quote = app(CreateQuote::class)->handle($test->lead->contact, BusinessLine::Interior, $test->sales, $test->lead, null, 'Invoice screen job');
    $editor = app(QuoteEditor::class);
    $section = $quote->sections()->first() ?? $editor->addSection($quote, 'Lounge');
    $editor->addLine($section, ['description' => 'Ceiling work', 'unit' => 'sqft', 'qty' => '1', 'rate_minor' => $rupees * 100]);
    $quote->items()->where('rate_minor', 0)->each(fn ($item) => $editor->deleteLine($item));

    app(RecalculateQuote::class)->handle($quote->refresh(), $test->sales);
    $sent = app(SendQuote::class)->handle($quote->refresh(), $test->sales)->quote->refresh();

    $test->post(QuoteLinks::signed('quotes.portal.accept', $sent), [
        'revision' => $sent->revision,
        'name' => 'Ayesha Khan',
        'confirm' => '1',
    ]);

    return Invoice::query()->where('quote_id', $sent->id)->sole();
}

it('shows one invoice in full: lines, totals, client and the actions that belong to it', function () {
    $invoice = screenInvoice($this);

    $this->actingAs($this->owner)
        ->get(route('admin.invoices.show', $invoice))
        ->assertOk()
        ->assertSee($invoice->number)
        ->assertSee($invoice->contact->name)
        ->assertSee($invoice->items->first()->description)
        ->assertSee('Collect a payment')
        ->assertSee('Void invoice');
});

it('links every row of the invoice list to its invoice', function () {
    $invoice = screenInvoice($this);

    $this->actingAs($this->owner)
        ->get(route('admin.invoices.index'))
        ->assertOk()
        ->assertSee(route('admin.invoices.show', $invoice), false);
});

it('keeps people without the money permission out of the invoice', function () {
    $invoice = screenInvoice($this);

    $this->actingAs($this->support)->get(route('admin.invoices.show', $invoice))->assertForbidden();
});

it('collects a payment from the invoice, verifies it and posts it to the ledger', function () {
    $invoice = screenInvoice($this);
    $before = (int) $invoice->balance_minor;

    Livewire::actingAs($this->owner)
        ->test(InvoiceShow::class, ['invoice' => $invoice])
        ->set('amount', '500.00')
        ->set('method', 'cash')
        ->set('reference', 'QA cash')
        ->call('collect')
        ->assertHasNoErrors();

    $payment = Payment::query()->where('invoice_id', $invoice->id)->sole();

    expect($payment->amount_minor)->toBe(50_000)
        ->and($payment->status->value)->toBe('verified')
        ->and((int) $invoice->refresh()->paid_minor)->toBe(50_000)
        ->and((int) $invoice->balance_minor)->toBe($before - 50_000)
        ->and(JournalEntry::query()->where('source_type', 'payment')->where('source_id', $payment->id)->exists())->toBeTrue();
});

it('refuses to collect more than is outstanding', function () {
    $invoice = screenInvoice($this);

    Livewire::actingAs($this->owner)
        ->test(InvoiceShow::class, ['invoice' => $invoice])
        ->set('amount', '999999.00')
        ->call('collect')
        ->assertHasErrors(['amount']);

    expect(Payment::query()->where('invoice_id', $invoice->id)->count())->toBe(0);
});

it('voids an issued invoice with a reason and reverses its ledger entry instead of deleting it', function () {
    $invoice = screenInvoice($this);

    $journal = JournalEntry::query()->where('source_type', 'invoice')->where('source_id', $invoice->id)->sole();

    Livewire::actingAs($this->owner)
        ->test(InvoiceShow::class, ['invoice' => $invoice])
        ->call('openVoid')
        ->set('voidReason', 'Raised against the wrong client')
        ->call('void')
        ->assertHasNoErrors();

    $invoice->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::Void)
        ->and($invoice->voided_at)->not->toBeNull()
        ->and($invoice->void_reason)->toBe('Raised against the wrong client')
        ->and((int) $invoice->balance_minor)->toBe(0);

    $reversal = JournalEntry::query()->where('reverses_entry_id', $journal->id)->sole();

    expect($journal->refresh()->isReversed())->toBeTrue()
        ->and($reversal->isReversal())->toBeTrue()
        ->and($journal->totals()['debit_minor'])->toBe($reversal->totals()['credit_minor']);
});

it('refuses to void an invoice that has money against it', function () {
    $invoice = screenInvoice($this);

    Livewire::actingAs($this->owner)
        ->test(InvoiceShow::class, ['invoice' => $invoice])
        ->set('amount', '100.00')
        ->call('collect')
        ->assertHasNoErrors();

    Livewire::actingAs($this->owner)
        ->test(InvoiceShow::class, ['invoice' => $invoice->refresh()])
        ->call('openVoid')
        ->set('voidReason', 'Trying anyway')
        ->call('void')
        ->assertHasErrors(['voidReason']);

    expect($invoice->refresh()->status)->not->toBe(InvoiceStatus::Void);
});

it('requires a reason before voiding', function () {
    $invoice = screenInvoice($this);

    Livewire::actingAs($this->owner)
        ->test(InvoiceShow::class, ['invoice' => $invoice])
        ->call('openVoid')
        ->set('voidReason', '')
        ->call('void')
        ->assertHasErrors(['voidReason']);

    expect($invoice->refresh()->status)->not->toBe(InvoiceStatus::Void);
});

it('does not offer the void to a sales user, and refuses it if called', function () {
    $invoice = screenInvoice($this);

    $this->actingAs($this->sales)
        ->get(route('admin.invoices.show', $invoice))
        ->assertOk()
        ->assertDontSee('Void invoice');

    Livewire::actingAs($this->sales)
        ->test(InvoiceShow::class, ['invoice' => $invoice])
        ->call('openVoid')
        ->assertForbidden();
});
