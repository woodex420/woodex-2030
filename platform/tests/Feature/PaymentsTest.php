<?php

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\AcceptQuote;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\RecalculateQuote;
use App\Domain\Sales\Actions\RecordPayment;
use App\Domain\Sales\Actions\RejectPayment;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Actions\SubmitPaymentProof;
use App\Domain\Sales\Actions\VerifyPayment;
use App\Domain\Sales\Enums\InvoiceKind;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Enums\PaymentStatus;
use App\Domain\Sales\Mail\PaymentMail;
use App\Domain\Sales\Models\BankAccount;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\InvoiceItem;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Services\InvoiceLinks;
use App\Domain\Sales\Services\QuoteEditor;
use App\Livewire\Admin\Sales\PaymentsIndex;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| Getting paid (INV-005, INV-006, INV-007): the studio records money it received, a client reports a payment with a
| proof from the invoice page, the accountant confirms it — which settles the invoices and writes a receipt — or
| sends it back with a reason the client reads in an email.
*/
beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 24)->setTime(11, 0));
    Storage::fake('local');
    Notification::fake();
    Mail::fake();

    $this->accountant = $this->userWithRole('accountant');
    $this->sales = $this->userWithRole('sales');
    $this->lead = Lead::factory()->create(['owner_id' => $this->sales->id, 'business_line' => 'interior']);
    $this->account = BankAccount::query()->create([
        'business_line' => BusinessLine::Interior,
        'title' => 'WOODEX INTERIOR',
        'bank_name' => 'Bank Alfalah',
        'iban' => 'PK00ALFA0000000000000000',
        'is_default' => true,
        'is_active' => true,
    ]);

    $this->invoice = advanceInvoice($this->lead, $this->sales);
    $this->invoice->contact->forceFill(['email' => 'ayesha@example.com'])->save();
});

/** A quotation of PKR 1,000,000, accepted, so its 50% advance invoice of PKR 500,000 exists. */
function advanceInvoice(Lead $lead, User $sales): Invoice
{
    $quote = app(CreateQuote::class)->handle($lead->contact, BusinessLine::Interior, $sales, $lead, null, 'Lounge');
    $editor = app(QuoteEditor::class);
    $section = $quote->sections()->first() ?? $editor->addSection($quote, 'Lounge');
    $editor->addLine($section, ['description' => 'Wall panelling', 'unit' => 'sqft', 'qty' => '1', 'rate_minor' => 1_000_000_00]);
    $quote->items()->where('rate_minor', 0)->each(fn ($item) => $editor->deleteLine($item));
    app(RecalculateQuote::class)->handle($quote->refresh(), $sales);
    $quote = app(SendQuote::class)->handle($quote->refresh(), $sales)->quote->refresh();

    app(AcceptQuote::class)->handle($quote, 'Ayesha Khan', null, '127.0.0.1', 'PHPUnit');

    return Invoice::query()->sole();
}

/** Another open invoice for the same client, to see how a payment spreads. */
function anotherInvoice(int $contactId, ?int $accountId, string $number, int $totalMinor, string $due): Invoice
{
    $invoice = Invoice::query()->create([
        'number' => $number,
        'kind' => InvoiceKind::Milestone,
        'status' => InvoiceStatus::Issued,
        'contact_id' => $contactId,
        'business_line' => BusinessLine::Interior,
        'bank_account_id' => $accountId,
        'currency' => 'PKR',
        'subtotal_minor' => $totalMinor,
        'total_minor' => $totalMinor,
        'balance_minor' => $totalMinor,
        'issue_date' => '2026-11-24',
        'due_date' => $due,
    ]);

    InvoiceItem::query()->create([
        'invoice_id' => $invoice->id,
        'description' => 'Second milestone',
        'qty' => 1,
        'rate_minor' => $totalMinor,
        'line_total_minor' => $totalMinor,
        'position' => 0,
    ]);

    return $invoice;
}

it('records money the studio received, settles the invoice and writes a receipt', function () {
    $payment = app(RecordPayment::class)->handle([
        'contact_id' => $this->invoice->contact_id,
        'invoice_id' => $this->invoice->id,
        'amount_minor' => 500_000_00,
        'received_on' => '2026-11-24',
        'method' => 'bank_transfer',
        'reference' => 'FT-99120',
        'bank_account_id' => $this->account->id,
    ], $this->accountant);

    expect($payment->status)->toBe(PaymentStatus::Verified)
        ->and($payment->receipt_number)->toBe('RC-2026-0001')
        ->and($payment->receipt_pdf_path)->not->toBeNull()
        ->and(Storage::disk('local')->exists((string) $payment->receipt_pdf_path))->toBeTrue();

    $invoice = $this->invoice->refresh();

    expect((int) $invoice->paid_minor)->toBe(500_000_00)
        ->and((int) $invoice->balance_minor)->toBe(0)
        ->and($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->allocations)->toHaveCount(1);

    Mail::assertQueued(PaymentMail::class);
});

it('spreads a payment over the oldest invoices first and keeps the rest as credit', function () {
    $second = anotherInvoice($this->invoice->contact_id, $this->account->id, 'WI-10124-1', 300_000_00, '2026-12-01');

    $payment = app(RecordPayment::class)->handle([
        'contact_id' => $this->invoice->contact_id,
        'amount_minor' => 600_000_00, // 500,000 settles the first, 100,000 goes to the second
        'received_on' => '2026-11-24',
        'method' => 'cash',
    ], $this->accountant);

    expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Paid)
        ->and((int) $second->refresh()->paid_minor)->toBe(100_000_00)
        ->and($second->status)->toBe(InvoiceStatus::PartiallyPaid)
        ->and($payment->allocations()->count())->toBe(2)
        ->and($payment->unallocated()->minor)->toBe(0);

    // More than everything that is owed: what is left stays on the client's account as credit.
    $over = app(RecordPayment::class)->handle([
        'contact_id' => $this->invoice->contact_id,
        'amount_minor' => 500_000_00,
        'received_on' => '2026-11-25',
        'method' => 'cash',
    ], $this->accountant);

    expect((int) $second->refresh()->balance_minor)->toBe(0)
        ->and($over->unallocated()->minor)->toBe(300_000_00); // 200,000 settled it, 300,000 is credit
});

it('takes a payment proof from the client and waits for the accountant', function () {
    $this->post(InvoiceLinks::signed('invoices.portal.proof', $this->invoice), [
        'amount' => '500000',
        'received_on' => '2026-11-24',
        'method' => PaymentMethod::JazzCash->value,
        'reference' => 'JC-7781',
        'proof' => UploadedFile::fake()->image('slip.jpg'),
    ])->assertRedirect();

    $payment = Payment::query()->sole();

    expect($payment->status)->toBe(PaymentStatus::PendingVerification)
        ->and($payment->submitted_by)->toBe('client')
        ->and((int) $payment->amount_minor)->toBe(500_000_00)
        ->and($payment->invoice_id)->toBe($this->invoice->id)
        ->and($payment->getMedia('files'))->toHaveCount(1);

    // Nothing is settled and no receipt exists until somebody confirms it.
    expect((int) $this->invoice->refresh()->paid_minor)->toBe(0)
        ->and($payment->receipt_number)->toBeNull();

    Mail::assertNothingQueued();
});

it('confirms a reported payment: settles the invoice, writes the receipt, emails the client', function () {
    $payment = app(SubmitPaymentProof::class)->handle($this->invoice, [
        'amount_minor' => 500_000_00,
        'received_on' => '2026-11-24',
        'method' => PaymentMethod::BankTransfer->value,
        'reference' => 'FT-4410',
    ]);

    $verified = app(VerifyPayment::class)->handle($payment, $this->accountant);

    expect($verified->status)->toBe(PaymentStatus::Verified)
        ->and($verified->receipt_number)->toBe('RC-2026-0001')
        ->and($verified->verifier->id)->toBe($this->accountant->id)
        ->and($this->invoice->refresh()->status)->toBe(InvoiceStatus::Paid);

    Mail::assertQueued(PaymentMail::class);
});

it('sends a payment back to the client with a reason', function () {
    $payment = app(SubmitPaymentProof::class)->handle($this->invoice, [
        'amount_minor' => 500_000_00,
        'received_on' => '2026-11-24',
        'method' => PaymentMethod::BankTransfer->value,
    ]);

    app(RejectPayment::class)->handle($payment, 'We cannot see this transfer yet. Please check the reference.', $this->accountant);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Rejected)
        ->and($payment->rejected_reason)->toBe('We cannot see this transfer yet. Please check the reference.')
        ->and((int) $this->invoice->refresh()->paid_minor)->toBe(0)
        ->and($payment->receipt_number)->toBeNull();

    Mail::assertQueued(PaymentMail::class);
});

it('shows the invoice to the client on a signed link, with the bank details and the payment form', function () {
    $this->get(InvoiceLinks::signed('invoices.portal', $this->invoice))
        ->assertOk()
        ->assertSee($this->invoice->number)
        ->assertSee('WOODEX INTERIOR')
        ->assertSee('IBAN')
        ->assertSee('I have paid');

    $this->get(InvoiceLinks::signed('invoices.portal.pdf', $this->invoice))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('hides a draft invoice from the client, and the payment form once it is settled', function () {
    $draft = $this->invoice->fresh();
    $draft->forceFill(['status' => InvoiceStatus::Draft, 'pdf_path' => null])->save();

    $this->get(InvoiceLinks::signed('invoices.portal', $draft))->assertNotFound();

    // Restore the issued fixture before recording money: a draft is not a payable invoice.
    $draft->forceFill(['status' => InvoiceStatus::Issued, 'pdf_path' => $this->invoice->pdf_path])->save();

    app(RecordPayment::class)->handle([
        'contact_id' => $this->invoice->contact_id,
        'invoice_id' => $this->invoice->id,
        'amount_minor' => 500_000_00,
        'received_on' => '2026-11-24',
        'method' => 'bank_transfer',
    ], $this->accountant);

    $this->invoice->forceFill(['status' => InvoiceStatus::Paid, 'pdf_path' => 'invoices/x/y.pdf'])->save();
    Storage::disk('local')->put('invoices/x/y.pdf', '%PDF-1.4 test');

    $this->get(InvoiceLinks::signed('invoices.portal', $this->invoice))
        ->assertOk()
        ->assertSee('This invoice is paid')
        ->assertDontSee('I have paid');
});

it('lists the payments, and only accounts may confirm one', function () {
    $payment = app(SubmitPaymentProof::class)->handle($this->invoice, [
        'amount_minor' => 500_000_00,
        'received_on' => '2026-11-24',
        'method' => PaymentMethod::Cheque->value,
        'reference' => 'CHQ-1122',
    ]);

    $this->actingAs($this->accountant)->get(route('admin.payments.index'))
        ->assertOk()
        ->assertSee('CHQ-1122')
        ->assertSee($this->invoice->contact->name);

    Livewire::actingAs($this->accountant)->test(PaymentsIndex::class)->call('verify', $payment->id)->assertHasNoErrors();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Verified);

    // Sales may see the money but not confirm it.
    $this->actingAs($this->sales)->get(route('admin.payments.index'))->assertOk();
    expect($this->sales->can('verify', $payment->refresh()))->toBeFalse();
});

it('records a payment from the dashboard screen', function () {
    Livewire::actingAs($this->accountant)
        ->test(PaymentsIndex::class)
        ->set('contactId', $this->invoice->contact_id)
        ->set('invoiceId', $this->invoice->id)
        ->set('amount', '500000')
        ->set('receivedOn', '2026-11-24')
        ->set('method', 'bank_transfer')
        ->set('reference', 'FT-2024')
        ->call('save')
        ->assertHasNoErrors();

    $payment = Payment::query()->sole();

    expect($payment->status)->toBe(PaymentStatus::Verified)
        ->and($payment->receipt_number)->toBe('RC-2026-0001')
        ->and($this->invoice->refresh()->status)->toBe(InvoiceStatus::Paid);
});
