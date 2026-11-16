<?php

declare(strict_types=1);

use App\Domain\Accounts\Enums\BillStatus;
use App\Domain\Accounts\Models\Bill;
use App\Domain\Accounts\Models\Vendor;
use App\Domain\Assistant\Assistant;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Enums\PaymentStatus;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Livewire\Admin\AssistantDrawer;
use App\Settings\SalesSettings;
use Livewire\Livewire;

/*
| Slice M — the assistant. Keyless and deterministic by design: a fixed whitelist of read-only questions, each
| answered by the same query its screen uses, with the records cited. The acceptance bar from the master plan:
| "what is overdue?" returns the same rows as Invoice tracking, and anything outside the whitelist says so.
*/

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
    $this->support = $this->userWithRole('support');
    app(SalesSettings::class)->fill(['lakh_grouping' => false])->save();
});

function assistantInvoice(string $number, int $balanceMinor, string $dueDate, InvoiceStatus $status = InvoiceStatus::Issued): Invoice
{
    return Invoice::query()->create([
        'number' => $number,
        'kind' => 'manual',
        'status' => $status->value,
        'contact_id' => Contact::factory()->create(['name' => 'Client '.$number])->id,
        'business_line' => 'interior',
        'currency' => 'PKR',
        'subtotal_minor' => $balanceMinor,
        'discount_minor' => 0,
        'tax_minor' => 0,
        'total_minor' => $balanceMinor,
        'paid_minor' => 0,
        'balance_minor' => $balanceMinor,
        'issue_date' => today()->subDays(30)->toDateString(),
        'due_date' => $dueDate,
    ]);
}

it('answers "what is overdue?" with exactly the tracking board rows and total', function () {
    assistantInvoice('T-OVD-1', 200_000 * 100, today()->subDays(10)->toDateString());
    assistantInvoice('T-OVD-2', 50_000 * 100, today()->subDay()->toDateString());
    assistantInvoice('T-CUR-1', 90_000 * 100, today()->addDays(5)->toDateString());
    assistantInvoice('T-PAID-1', 0, today()->subDays(3)->toDateString(), InvoiceStatus::Paid);

    $answer = app(Assistant::class)->ask('what is overdue?', $this->owner);

    $boardRows = Invoice::query()->overdue()->orderBy('due_date')->get();
    expect($answer->refused)->toBeFalse()
        ->and($answer->totalMinor)->toBe(250_000 * 100)
        ->and(array_column($answer->rows, 'label'))->toHaveCount($boardRows->count())
        ->and($answer->rows[0]['label'])->toContain($boardRows[0]->number)
        ->and($answer->headline)->toContain('2 invoices overdue')
        ->and($answer->headline)->toContain('Rs 250,000')
        ->and($answer->source)->toContain('Invoice tracking');

    // Same answer by chip id, and the drawer carries it with its citations.
    Livewire::actingAs($this->owner)->test(AssistantDrawer::class)
        ->assertSee('What is overdue?')
        ->call('askChip', 'overdue_invoices')
        ->assertSee('T-OVD-1')
        ->assertSee('Rs 250,000')
        ->assertSee('Read from:');
});

it('says plainly when a question is outside the whitelist', function () {
    $answer = app(Assistant::class)->ask('write me a poem about walnut veneer', $this->owner);

    expect($answer->refused)->toBeTrue()
        ->and($answer->rows)->toBe([])
        ->and($answer->headline)->toContain("I don't have a query for that")
        ->and($answer->headline)->toContain('overdue');
});

it('never shows a record the asker could not open', function () {
    assistantInvoice('T-OVD-X', 10_000 * 100, today()->subDays(2)->toDateString());

    // Support has no invoices.view: the refusal names the permission and cites nothing.
    $answer = app(Assistant::class)->ask('what is overdue?', $this->support);

    expect($answer->refused)->toBeTrue()
        ->and($answer->rows)->toBe([])
        ->and($answer->headline)->toContain('invoices.view');

    // The drawer's chips agree: no invoice questions offered to support.
    Livewire::actingAs($this->support)->test(AssistantDrawer::class)
        ->assertDontSee('What is overdue?')
        ->assertSee('Which leads need follow-up?');
});

it('counts collected money from verified payments in the month only', function () {
    $invoice = assistantInvoice('T-COL-1', 0, today()->toDateString(), InvoiceStatus::Paid);

    $make = fn (PaymentStatus $status, string $on, int $minor) => Payment::query()->create([
        'contact_id' => $invoice->contact_id,
        'invoice_id' => $invoice->id,
        'amount_minor' => $minor,
        'received_on' => $on,
        'method' => 'cash',
        'status' => $status->value,
        'created_by' => $this->owner->id,
    ]);

    $make(PaymentStatus::Verified, today()->toDateString(), 120_000 * 100);
    $make(PaymentStatus::PendingVerification, today()->toDateString(), 999_000 * 100);
    $make(PaymentStatus::Verified, today()->subMonth()->toDateString(), 888_000 * 100);

    $answer = app(Assistant::class)->ask('how much did we collect this month?', $this->owner);

    expect($answer->totalMinor)->toBe(120_000 * 100)
        ->and($answer->headline)->toContain('Rs 120,000');
});

it('sums open bills as what we owe suppliers', function () {
    $vendor = Vendor::query()->create(['name' => 'Chughtai Timber', 'is_active' => true]);

    $bill = fn (string $number, BillStatus $status, int $total, int $paid) => Bill::query()->create([
        'number' => $number,
        'vendor_id' => $vendor->id,
        'bill_date' => today()->toDateString(),
        'due_date' => today()->addDays(10)->toDateString(),
        'status' => $status,
        'subtotal_minor' => $total,
        'tax_minor' => 0,
        'total_minor' => $total,
        'paid_minor' => $paid,
        'created_by' => $this->owner->id,
    ]);

    $bill('VB-1', BillStatus::Received, 100_000 * 100, 0);
    $bill('VB-2', BillStatus::PartiallyPaid, 80_000 * 100, 30_000 * 100);
    $bill('VB-3', BillStatus::Paid, 70_000 * 100, 70_000 * 100);
    $bill('VB-4', BillStatus::Draft, 60_000 * 100, 0);

    $answer = app(Assistant::class)->ask('what do we owe suppliers?', $this->owner);

    expect($answer->totalMinor)->toBe(150_000 * 100)
        ->and($answer->headline)->toContain('Rs 150,000')
        ->and(array_column($answer->rows, 'label'))->toHaveCount(2);
});

it('uses the one follow-up rule for leads and cites only visible ones', function () {
    $mine = Lead::factory()->create([
        'owner_id' => $this->support->id,
        'contact_id' => Contact::factory()->create(['owner_id' => $this->support->id])->id,
        'next_action_at' => now()->subDay(),
    ]);
    Lead::factory()->create([
        'owner_id' => $this->owner->id,
        'contact_id' => Contact::factory()->create()->id,
        'next_action_at' => now()->subDay(),
    ]);

    $answer = app(Assistant::class)->ask('which leads need follow-up?', $this->support);

    expect($answer->refused)->toBeFalse();
    expect(implode(' ', array_column($answer->rows, 'label')))->toContain($mine->ref);
    expect($answer->rows)->toHaveCount(1);
});

it('reports quotes waiting with the client, soonest to lapse first', function () {
    $contact = Contact::factory()->create();
    $lead = Lead::factory()->create(['contact_id' => $contact->id, 'owner_id' => $this->owner->id]);

    $quote = app(CreateQuote::class)->handle($contact, BusinessLine::Interior, $this->owner, $lead, null, 'Kitchen');
    $quote->forceFill(['status' => QuoteStatus::Sent->value, 'total_minor' => 400_000 * 100])->save();

    $answer = app(Assistant::class)->ask('which quotes are waiting?', $this->owner);

    expect($answer->totalMinor)->toBe(400_000 * 100)
        ->and($answer->rows[0]['label'])->toContain($quote->number);
});

it('is mounted on every admin page', function () {
    $this->actingAs($this->owner)->get('/admin')->assertOk()->assertSee('Ask, e.g. what is overdue?');
    $this->actingAs($this->owner)->get('/admin/quotes')->assertOk()->assertSee('Ask, e.g. what is overdue?');
});
