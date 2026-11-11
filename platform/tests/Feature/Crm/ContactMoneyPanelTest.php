<?php

declare(strict_types=1);

use App\Domain\Crm\Models\Contact;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use Illuminate\Support\Facades\Storage;

/*
| CRM / Sales deep QA, part 2: the client record shows the money.
|
| The contact page listed enquiries and quotations but never said what the client had actually been invoiced, paid
| or still owed — the statement existed only as a PDF. The panel is a straight read of the invoice and payment rows,
| so the tests pin the arithmetic: voided invoices are out of every figure, paid and outstanding come from the
| invoice rows, and a colleague without invoices.view does not see it at all.
*/

beforeEach(function () {
    Storage::fake('local');

    $this->owner = $this->userWithRole('owner');
    $this->support = $this->userWithRole('support');

    // Support sees only the people they own or share an enquiry with, so the money-panel check gives them one.
    $this->contact = Contact::factory()->create(['name' => 'Money Panel Client', 'owner_id' => $this->support->id]);
});

function panelInvoice(Contact $contact, string $number, int $totalMinor, int $paidMinor, InvoiceStatus $status): Invoice
{
    return Invoice::query()->create([
        'number' => $number,
        'kind' => 'manual',
        'status' => $status->value,
        'contact_id' => $contact->id,
        'business_line' => 'interior',
        'currency' => 'PKR',
        'subtotal_minor' => $totalMinor,
        'discount_minor' => 0,
        'tax_minor' => 0,
        'total_minor' => $totalMinor,
        'paid_minor' => $paidMinor,
        'balance_minor' => $totalMinor - $paidMinor,
        'issue_date' => today()->toDateString(),
        'due_date' => today()->addDays(7)->toDateString(),
    ]);
}

it('sums what the client was invoiced, paid and still owes from the real rows', function () {
    panelInvoice($this->contact, 'T-PANEL-1', 10_000_000, 4_000_000, InvoiceStatus::PartiallyPaid);
    panelInvoice($this->contact, 'T-PANEL-2', 5_000_000, 5_000_000, InvoiceStatus::Paid);

    $this->actingAs($this->owner)
        ->get(route('admin.contacts.show', $this->contact))
        ->assertOk()
        ->assertSee('Money')
        ->assertSee('Awaiting decision')
        ->assertSee('150,000')   // invoiced
        ->assertSee('90,000')    // paid
        ->assertSee('60,000')    // outstanding
        ->assertSee('T-PANEL-1')
        ->assertSee(route('admin.invoices.show', Invoice::query()->where('number', 'T-PANEL-1')->sole()), false);
});

it('leaves voided invoices out of every figure', function () {
    panelInvoice($this->contact, 'T-PANEL-3', 10_000_000, 0, InvoiceStatus::Issued);
    panelInvoice($this->contact, 'T-PANEL-4', 9_999_900, 0, InvoiceStatus::Void);

    $this->actingAs($this->owner)
        ->get(route('admin.contacts.show', $this->contact))
        ->assertOk()
        ->assertSee('100,000')
        ->assertDontSee('199,999');
});

it('says plainly when nothing has been paid yet', function () {
    panelInvoice($this->contact, 'T-PANEL-5', 2_000_000, 0, InvoiceStatus::Issued);

    $this->actingAs($this->owner)
        ->get(route('admin.contacts.show', $this->contact))
        ->assertOk()
        ->assertSee('No payment recorded yet')
        ->assertSee('20,000');
});

it('shows the date of the last verified payment', function () {
    $invoice = panelInvoice($this->contact, 'T-PANEL-6', 3_000_000, 1_000_000, InvoiceStatus::PartiallyPaid);

    Payment::query()->create([
        'contact_id' => $this->contact->id,
        'invoice_id' => $invoice->id,
        'amount_minor' => 1_000_000,
        'received_on' => '2026-10-02',
        'method' => 'cash',
        'status' => 'verified',
    ]);

    $this->actingAs($this->owner)
        ->get(route('admin.contacts.show', $this->contact))
        ->assertOk()
        ->assertSee('Last payment 2 Oct 2026');
});

it('hides the money panel from a colleague who may not see invoices', function () {
    panelInvoice($this->contact, 'T-PANEL-7', 1_000_000, 0, InvoiceStatus::Issued);

    $this->actingAs($this->support)
        ->get(route('admin.contacts.show', $this->contact))
        ->assertOk()
        ->assertDontSee('Awaiting decision')
        ->assertDontSee('T-PANEL-7');
});
