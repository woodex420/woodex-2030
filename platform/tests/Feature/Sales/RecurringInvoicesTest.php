<?php

declare(strict_types=1);

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Sales\Enums\InvoiceKind;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\RecurringInvoice;
use App\Livewire\Admin\Sales\RecurringInvoices;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| Recurring invoices (slice E, part 2): a retainer bills itself on its own rhythm.
|
| The schedule holds the lines and the price; the scheduler only decides when. One invoice per period, its own
| number series (RI-…), the frozen PDF and the ledger posting every issued invoice gets — and a paused or ended
| schedule bills nothing.
*/

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 24)->setTime(9, 30));
    Storage::fake('local');
    Notification::fake();

    $this->owner = $this->userWithRole('owner');
    $this->accountant = $this->userWithRole('accountant');
});

function recurringSchedule(array $attributes = []): RecurringInvoice
{
    return RecurringInvoice::query()->create(array_merge([
        'contact_id' => Contact::factory()->create(['consent_whatsapp' => true])->id,
        'title' => 'Monthly maintenance — DHA Phase 5',
        'business_line' => BusinessLine::Interior,
        'frequency' => 'monthly',
        'lines' => [[
            'description' => 'Monthly maintenance visit',
            'unit' => 'service',
            'qty' => 1,
            'rate_minor' => 25_000_00,
            'discount_minor' => 0,
        ]],
        'tax_bp' => 1_500,
        'due_days' => 7,
        'starts_on' => today()->subMonth()->toDateString(),
        'next_issue_on' => today()->toDateString(),
        'is_active' => true,
    ], $attributes));
}

it('bills a standing charge once per period, with its own number series', function () {
    $schedule = recurringSchedule();

    expect($this->artisan('woodex:invoice-recurring'))->toBe(0);

    $invoice = Invoice::query()->sole();

    expect($invoice->kind)->toBe(InvoiceKind::Recurring)
        ->and($invoice->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->number)->toStartWith('RI-2026-')
        ->and($invoice->subtotal_minor)->toBe(25_000_00)
        ->and($invoice->tax_minor)->toBe(3_750_00)          // 15% of the line
        ->and($invoice->total_minor)->toBe(28_750_00)
        ->and($invoice->balance_minor)->toBe(28_750_00)
        ->and($invoice->due_date->toDateString())->toBe(today()->addDays(7)->toDateString())
        ->and($invoice->pdf_path)->not->toBeNull();

    $schedule->refresh();

    expect($schedule->invoice_count)->toBe(1)
        ->and($schedule->next_issue_on->toDateString())->toBe(today()->addMonthNoOverflow()->toDateString())
        ->and($schedule->last_issued_at)->not->toBeNull();

    // Running again the same day bills nothing: one invoice per period.
    expect($this->artisan('woodex:invoice-recurring'))->toBe(0)
        ->and(Invoice::query()->count())->toBe(1);
});

it('catches up a missed period with one invoice, not a backlog', function () {
    $schedule = recurringSchedule(['next_issue_on' => today()->subDays(40)->toDateString()]);

    expect($this->artisan('woodex:invoice-recurring'))->toBe(0)
        ->and(Invoice::query()->count())->toBe(1);

    $schedule->refresh();

    expect($schedule->next_issue_on->isAfter(today()))->toBeTrue()
        ->and($schedule->invoice_count)->toBe(1);
});

it('bills nothing while paused or after its end date', function () {
    recurringSchedule(['is_active' => false]);
    recurringSchedule(['title' => 'Ended contract', 'ends_on' => today()->subDay()->toDateString()]);

    expect($this->artisan('woodex:invoice-recurring'))->toBe(0)
        ->and(Invoice::query()->count())->toBe(0);
});

it('switches itself off after the last period it was told to bill', function () {
    $schedule = recurringSchedule(['ends_on' => today()->toDateString()]);

    expect($this->artisan('woodex:invoice-recurring'))->toBe(0);

    expect($schedule->refresh()->is_active)->toBeFalse()
        ->and(Invoice::query()->count())->toBe(1);
});

it('starts a schedule from the screen and shows it in the list', function () {
    $contact = Contact::factory()->create(['name' => 'Ayesha Khan']);

    Livewire::actingAs($this->accountant)->test(RecurringInvoices::class)
        ->call('openForm')
        ->set('contactId', $contact->id)
        ->set('title', 'Quarterly deep clean')
        ->set('lineDescription', 'Deep clean of the whole house')
        ->set('lineQty', '1')
        ->set('lineRate', '18000')
        ->set('taxPercent', '15')
        ->set('frequency', 'quarterly')
        ->set('startsOn', today()->toDateString())
        ->set('dueDays', '10')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Quarterly deep clean');

    $schedule = RecurringInvoice::query()->sole();

    expect($schedule->frequency->value)->toBe('quarterly')
        ->and($schedule->tax_bp)->toBe(1500)
        ->and($schedule->lines[0]['rate_minor'])->toBe(18_000_00)
        ->and($schedule->totalMinor())->toBe(20_700_00)
        ->and($schedule->created_by)->toBe($this->accountant->id);
});

it('bills the current period on demand from the screen', function () {
    $schedule = recurringSchedule(['next_issue_on' => today()->addDays(12)->toDateString()]);

    Livewire::actingAs($this->accountant)->test(RecurringInvoices::class)
        ->call('issueNow', $schedule->id)
        ->assertHasNoErrors();

    expect(Invoice::query()->count())->toBe(1)
        ->and($schedule->refresh()->invoice_count)->toBe(1);
});

it('pauses and resumes from the screen', function () {
    $schedule = recurringSchedule();

    Livewire::actingAs($this->accountant)->test(RecurringInvoices::class)
        ->call('toggle', $schedule->id);

    expect($schedule->refresh()->is_active)->toBeFalse();

    Livewire::actingAs($this->accountant)->test(RecurringInvoices::class)
        ->call('toggle', $schedule->id);

    expect($schedule->refresh()->is_active)->toBeTrue();
});

it('keeps the screen read-only for a role without invoices.manage', function () {
    $sales = $this->userWithRole('sales');

    Livewire::actingAs($sales)->test(RecurringInvoices::class)
        ->call('openForm')
        ->assertForbidden();
});

it('opens the Recurring tab on the invoices page and lists the automation', function () {
    recurringSchedule();

    $this->actingAs($this->owner)->get('/admin/invoices?tab=recurring')
        ->assertOk()
        ->assertSee('Recurring invoices')
        ->assertSee('Monthly maintenance — DHA Phase 5');

    $this->actingAs($this->owner)->get('/admin/automation')
        ->assertOk()
        ->assertSee('Recurring invoices')
        ->assertSee('Daily 09:20');
});
