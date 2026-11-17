<?php

declare(strict_types=1);

use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\AcceptQuote;
use App\Domain\Sales\Actions\CollectPayment;
use App\Domain\Sales\Actions\CreateFurnitureInvoice;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Actions\RecordPayment;
use App\Domain\Sales\Actions\RejectPayment;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Actions\SubmitPaymentProof;
use App\Domain\Sales\Actions\VerifyPayment;
use App\Domain\Sales\Actions\VoidInvoice;
use App\Domain\Sales\Enums\AcceptanceChannel;
use App\Domain\Sales\Enums\DiscountType;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Services\InvoiceLinks;
use App\Domain\Sales\Services\QuoteEditor;
use App\Livewire\Admin\Crm\LeadShow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| What happens to the invoice and the money is on the client's timeline (list line T-080).
|
| The timeline of a lead and of a contact offers "Invoice" and "Payment" as filters, yet nothing wrote either kind:
| the record of a sale stopped at "accepted". The sales person who won the order could not see on their own lead
| whether the invoice had gone out or the advance had come in.
|
| The entries carry no amounts. A lead is read by everybody who may open it; the figures belong to those who may
| open the invoice (the money card of a contact is shown only with that right).
*/

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    Mail::fake();
    $this->sales = $this->userWithRole('sales');
    $this->accounts = $this->userWithRole('accountant');
    $this->lead = Lead::factory()->create([
        'owner_id' => $this->sales->id, 'business_line' => 'furniture', 'service' => 'office-furniture',
        'message' => 'Twelve workstations for a new office.', 'created_at' => now()->subDay(),
    ]);
    $this->lead->contact->update(['name' => 'Ayesha Khan', 'phone' => '0322 4000768', 'whatsapp' => '0322 4000768', 'email' => null]);
});

/** An accepted Furniture quotation of Rs 496,800 (12 × 45,000 less 8%), billed in full; issued unless told otherwise. */
function billedSale(bool $issue = true): Invoice
{
    $owner = test()->userWithRole('owner');
    $quote = app(CreateQuote::class)->handle(test()->lead->contact, BusinessLine::Furniture, $owner, test()->lead, null, 'Office furniture');
    $editor = app(QuoteEditor::class);
    $editor->updateLine($quote->items()->firstOrFail(), [
        'product_name' => 'Executive workstation', 'description' => 'Laminate top on a powder-coated frame.',
        'unit' => 'nos', 'qty' => '12', 'rate_minor' => 4_500_000,
    ]);
    $editor->updateDetails($quote->refresh(), ['discount_type' => DiscountType::Percent, 'discount_value' => 800]);
    app(SendQuote::class)->handle($quote->refresh(), $owner, 'link', false);
    app(AcceptQuote::class)->handle($quote->refresh(), 'Ayesha Khan', null, null, null, $owner, null, AcceptanceChannel::Phone);

    $invoice = app(CreateFurnitureInvoice::class)->handle($quote->refresh(), test()->accounts);

    return $issue ? app(IssueInvoice::class)->handle($invoice, test()->accounts) : $invoice;
}

/** The timeline entries of one kind for the sale's lead, oldest first. */
function timelineOf(ActivityType $type): array
{
    return Activity::query()->where('lead_id', test()->lead->id)->where('type', $type)->orderBy('id')->pluck('body')->all();
}

it('writes the issue of an invoice on the lead and on the contact, without the amount', function () {
    $draft = billedSale(issue: false);

    // A draft is nobody's business yet.
    expect(timelineOf(ActivityType::InvoiceEvent))->toBe([]);

    $invoice = app(IssueInvoice::class)->handle($draft, $this->accounts);

    $entry = Activity::query()->where('type', ActivityType::InvoiceEvent)->sole();

    expect($entry->body)->toBe("Invoice {$invoice->number} issued, due ".$invoice->due_date->format('j M Y').'.')
        ->and($entry->lead_id)->toBe($this->lead->id)
        ->and($entry->contact_id)->toBe($this->lead->contact_id)
        ->and($entry->user_id)->toBe($this->accounts->id)
        ->and($entry->subject_type)->toBe($invoice->getMorphClass())
        ->and($entry->subject_id)->toBe($invoice->id)
        ->and($entry->data)->toMatchArray(['invoice_event' => 'issued', 'invoice' => $invoice->number])
        // Rs 496,800 in any of the ways the admin writes it.
        ->and($entry->body)->not->toContain('496')
        ->and($entry->body)->not->toContain('4,96');
});

it('writes a payment taken by accounts, and says where the invoice then stands', function () {
    $invoice = billedSale();

    $advance = app(CollectPayment::class)->handle($invoice, 37_260_000, PaymentMethod::BankTransfer, $this->accounts, 'TRX-1');

    expect(timelineOf(ActivityType::Payment))->toBe([
        "Payment received: receipt {$advance->receipt_number}, against {$invoice->number} (part paid).",
    ]);

    $rest = app(CollectPayment::class)->handle($invoice->refresh(), (int) $invoice->balance_minor, PaymentMethod::Cash, $this->accounts);

    expect(timelineOf(ActivityType::Payment))->toHaveCount(2)
        ->and(timelineOf(ActivityType::Payment)[1])->toBe("Payment received: receipt {$rest->receipt_number}, against {$invoice->number} (paid in full).")
        ->and(implode(' ', timelineOf(ActivityType::Payment)))->not->toContain('372')
        ->and(Activity::query()->where('type', ActivityType::Payment)->latest('id')->first()->data)
        ->toMatchArray(['payment_event' => 'received', 'invoice' => $invoice->number, 'receipt' => $rest->receipt_number]);
});

it('writes a payment the client reported, then its confirmation', function () {
    $invoice = billedSale();

    $claim = app(SubmitPaymentProof::class)->handle($invoice, [
        'amount_minor' => 37_260_000, 'received_on' => today()->toDateString(), 'method' => 'bank_transfer', 'reference' => 'TRX-CLIENT-1',
    ], UploadedFile::fake()->image('slip.png'));

    expect(timelineOf(ActivityType::Payment))->toBe([
        "Ayesha Khan reported a payment against {$invoice->number}, with a slip. Accounts confirm it or send it back.",
    ])->and(Activity::query()->where('type', ActivityType::Payment)->sole()->user_id)->toBeNull();

    $verified = app(VerifyPayment::class)->handle($claim, $this->accounts);

    expect(timelineOf(ActivityType::Payment))->toHaveCount(2)
        ->and(timelineOf(ActivityType::Payment)[1])->toBe("Payment received: receipt {$verified->receipt_number}, against {$invoice->number} (part paid).");

    // Confirming twice writes nothing twice.
    app(VerifyPayment::class)->handle($verified, $this->accounts);

    expect(timelineOf(ActivityType::Payment))->toHaveCount(2);
});

it('writes a reported payment that accounts sent back, with the reason', function () {
    $invoice = billedSale();

    $claim = app(SubmitPaymentProof::class)->handle($invoice, [
        'amount_minor' => 37_260_000, 'received_on' => today()->toDateString(), 'method' => 'bank_transfer',
    ]);

    app(RejectPayment::class)->handle($claim, 'No such transfer in the bank statement.', $this->accounts);

    expect(timelineOf(ActivityType::Payment))->toBe([
        "Ayesha Khan reported a payment against {$invoice->number}. Accounts confirm it or send it back.",
        'The reported payment was sent back to the client: No such transfer in the bank statement.',
    ]);
});

it('finds the lead of a payment recorded against the oldest open invoice', function () {
    $invoice = billedSale();

    // Payments → "Record payment" with "Oldest open invoice": the payment itself names no invoice.
    $payment = app(RecordPayment::class)->handle([
        'contact_id' => $this->lead->contact_id, 'amount_minor' => 10_000_000, 'method' => 'cash', 'received_on' => today()->toDateString(),
    ], $this->accounts);

    expect($payment->invoice_id)->toBeNull()
        ->and(timelineOf(ActivityType::Payment))->toBe(["Payment received: receipt {$payment->receipt_number}, against {$invoice->number} (part paid)."]);
});

it('writes the voiding of an invoice with its reason', function () {
    $invoice = billedSale();

    app(VoidInvoice::class)->handle($invoice, $this->userWithRole('owner'), 'Raised for the wrong client.');

    expect(timelineOf(ActivityType::InvoiceEvent))->toHaveCount(2)
        ->and(timelineOf(ActivityType::InvoiceEvent)[1])->toBe("Invoice {$invoice->number} voided: Raised for the wrong client.");
});

it('shows the sales person on their own lead that the invoice went out and the advance came in', function () {
    $invoice = billedSale();
    $advance = app(CollectPayment::class)->handle($invoice, 37_260_000, PaymentMethod::BankTransfer, $this->accounts, 'TRX-1');

    Livewire::actingAs($this->sales)->test(LeadShow::class, ['lead' => $this->lead->refresh()])
        ->assertSee("Invoice {$invoice->number} issued, due")
        ->assertSee("Payment received: receipt {$advance->receipt_number}, against {$invoice->number} (part paid).")
        // The two filters of the timeline now have something to find.
        ->set('activityType', ActivityType::Payment->value)
        ->assertSee("Payment received: receipt {$advance->receipt_number}")
        ->assertDontSee("Invoice {$invoice->number} issued, due")
        ->set('activityType', ActivityType::InvoiceEvent->value)
        ->assertSee("Invoice {$invoice->number} issued, due")
        ->assertDontSee("Payment received: receipt {$advance->receipt_number}");
});

it('shows the value typed for a lead in its details, not a leftover of the screen', function () {
    // The Details card printed the last key of a loop further up the view ("call_back") in place of the value.
    $this->lead->update(['expected_value_minor' => 49_680_000]);

    Livewire::actingAs($this->sales)->test(LeadShow::class, ['lead' => $this->lead->refresh()])
        ->assertSeeInOrder(['Value', 'Rs 496,800', 'Score'])
        ->assertDontSeeHtml('<dd class="col-span-2 text-gray-800 dark:text-white/90">call_back</dd>');

    $this->lead->update(['expected_value_minor' => null]);

    Livewire::actingAs($this->sales)->test(LeadShow::class, ['lead' => $this->lead->refresh()])
        ->assertDontSeeHtml('<dd class="col-span-2 text-gray-800 dark:text-white/90">call_back</dd>');
});

it('explains on the client page of an invoice what lies between the lines and the total', function () {
    config(['modules.enabled' => 'lead_api,client_portal']);
    $invoice = billedSale();

    $this->get(InvoiceLinks::signed('invoices.portal', $invoice))
        ->assertOk()
        ->assertSeeInOrder(['What this covers', 'Rs 540,000', 'Subtotal', 'Rs 540,000', 'Discount', '− Rs 43,200', 'Invoice total', 'Rs 496,800'])
        ->assertDontSee('Sales tax')
        ->assertDontSee('Rounding');
});

it('keeps the client page of an invoice without adjustments as it was', function () {
    config(['modules.enabled' => 'lead_api,client_portal']);
    $invoice = billedSale();
    // The same invoice as it would stand with no discount: the lines are the total, nothing is between them.
    Invoice::query()->whereKey($invoice->id)->update(['discount_minor' => 0, 'subtotal_minor' => 49_680_000]);

    $this->get(InvoiceLinks::signed('invoices.portal', $invoice->refresh()))
        ->assertOk()
        ->assertDontSee('Subtotal')
        ->assertDontSee('data-invoice-sums', false);
});
