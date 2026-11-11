<?php

declare(strict_types=1);

use App\Domain\Accounts\Actions\PayBill;
use App\Domain\Accounts\Enums\BillStatus;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\Bill;
use App\Domain\Accounts\Models\BillPayment;
use App\Domain\Accounts\Models\JournalEntry;
use App\Domain\Accounts\Models\JournalLine;
use App\Domain\Accounts\Models\Vendor;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Livewire\Admin\Accounts\BillsIndex;
use App\Livewire\Admin\Accounts\PayablesAging;
use App\Livewire\Admin\Accounts\VendorsIndex;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| Slice I — money out. Until this slice the ledger only ever heard about money arriving: there was no vendor,
| no received invoice, no expense and no payable. These tests hold the money-out loop to the same standard the
| receivable side is held to: every bill posts balanced, every payment clears exactly what it says, a mistake is
| reversed rather than deleted, and the trial balance still agrees with itself afterwards.
*/

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
    $this->support = $this->userWithRole('support');

    $this->vendor = Vendor::query()->create([
        'name' => 'Chughtai Timber',
        'phone' => '0300 1234567',
        'payment_terms_days' => 15,
        'is_active' => true,
    ]);

    $this->materials = Account::query()->where('code', '5010')->firstOrFail();
    $this->subcontractors = Account::query()->where('code', '5030')->firstOrFail();
});

function receivedBill(object $test, string $number = 'VB-1001', int $netRupees = 100_000, int $taxRupees = 0): Bill
{
    $bill = Bill::query()->create([
        'number' => $number,
        'vendor_id' => $test->vendor->id,
        'bill_date' => today()->toDateString(),
        'due_date' => today()->addDays(15)->toDateString(),
        'status' => BillStatus::Draft,
        'subtotal_minor' => $netRupees * 100,
        'tax_minor' => $taxRupees * 100,
        'total_minor' => ($netRupees + $taxRupees) * 100,
        'created_by' => $test->owner->id,
    ]);

    $bill->items()->create([
        'account_id' => $test->materials->id,
        'description' => 'Walnut veneer',
        'amount_minor' => $netRupees * 100,
        'position' => 0,
    ]);

    return $bill->refresh();
}

it('adds a vendor from the screen and lists it', function () {
    Livewire::actingAs($this->owner)
        ->test(VendorsIndex::class)
        ->call('openForm')
        ->set('name', 'Al-Madina Polish')
        ->set('phone', '0321 7654321')
        ->set('city', 'Lahore')
        ->set('termsDays', '30')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Al-Madina Polish');

    expect(Vendor::query()->where('name', 'Al-Madina Polish')->value('payment_terms_days'))->toBe(30);
});

it('keeps a draft out of the books and posts it when received', function () {
    Livewire::actingAs($this->owner)
        ->test(BillsIndex::class)
        ->call('openForm')
        ->set('vendorId', $this->vendor->id)
        ->set('number', 'VB-2001')
        ->set('billDate', today()->toDateString())
        ->set('lines', [['accountId' => $this->materials->id, 'description' => 'MDF sheets', 'amountRupees' => '50000']])
        ->call('saveDraft')
        ->assertHasNoErrors();

    $bill = Bill::query()->where('number', 'VB-2001')->sole();

    expect($bill->status)->toBe(BillStatus::Draft)
        ->and(JournalEntry::query()->where('source_type', 'bill')->where('source_id', $bill->id)->exists())->toBeFalse();

    Livewire::actingAs($this->owner)->test(BillsIndex::class)->call('post', $bill->id);

    $entry = JournalEntry::query()->where('source_type', 'bill')->where('source_id', $bill->id)->sole();
    $debit = (int) JournalLine::query()->where('journal_entry_id', $entry->id)->sum('debit_minor');
    $credit = (int) JournalLine::query()->where('journal_entry_id', $entry->id)->sum('credit_minor');

    expect($bill->refresh()->status)->toBe(BillStatus::Received)
        ->and($debit)->toBe(50000 * 100)
        ->and($credit)->toBe(50000 * 100)
        ->and(JournalLine::query()->where('journal_entry_id', $entry->id)->where('account_id', $this->materials->id)->value('debit_minor'))->toBe(50000 * 100)
        ->and(JournalLine::query()->where('journal_entry_id', $entry->id)->where('account_id', Account::query()->where('code', '2010')->value('id'))->value('credit_minor'))->toBe(50000 * 100);
});

it('books the vendor tax to input tax so the payable stays exact', function () {
    $bill = receivedBill($this, 'VB-3001', 100_000, 15_000);

    Livewire::actingAs($this->owner)->test(BillsIndex::class)->call('post', $bill->id);

    $entry = JournalEntry::query()->where('source_type', 'bill')->where('source_id', $bill->id)->sole();
    $lines = JournalLine::query()->where('journal_entry_id', $entry->id)->get()->keyBy('account_id');

    expect((int) $lines[Account::query()->where('code', '1210')->value('id')]->debit_minor)->toBe(15_000 * 100)
        ->and((int) $lines[Account::query()->where('code', '5010')->value('id')]->debit_minor)->toBe(100_000 * 100)
        ->and((int) $lines[Account::query()->where('code', '2010')->value('id')]->credit_minor)->toBe(115_000 * 100);
});

it('refuses the same invoice number twice from the same vendor', function () {
    receivedBill($this, 'VB-4001');

    Livewire::actingAs($this->owner)
        ->test(BillsIndex::class)
        ->call('openForm')
        ->set('vendorId', $this->vendor->id)
        ->set('number', 'VB-4001')
        ->set('billDate', today()->toDateString())
        ->set('lines', [['accountId' => $this->materials->id, 'description' => 'Duplicate', 'amountRupees' => '1000']])
        ->call('saveReceived')
        ->assertHasErrors('number');

    expect(Bill::query()->where('number', 'VB-4001')->count())->toBe(1);
});

it('pays a bill in two parts and clears the payable to zero', function () {
    $bill = receivedBill($this, 'VB-5001', 100_000);
    Livewire::actingAs($this->owner)->test(BillsIndex::class)->call('post', $bill->id);

    app(PayBill::class)->handle($bill->refresh(), 40_000 * 100, PaymentMethod::BankTransfer, $this->owner);

    expect($bill->refresh()->status)->toBe(BillStatus::PartiallyPaid)
        ->and($bill->balanceMinor())->toBe(60_000 * 100);

    app(PayBill::class)->handle($bill->refresh(), 60_000 * 100, PaymentMethod::BankTransfer, $this->owner);

    $payable = Account::query()->where('code', '2010')->value('id');
    $debitsToPayable = (int) JournalLine::query()
        ->where('account_id', $payable)
        ->whereIn('journal_entry_id', JournalEntry::query()->where('source_type', 'bill_payment')->pluck('id'))
        ->sum('debit_minor');

    expect($bill->refresh()->status)->toBe(BillStatus::Paid)
        ->and($bill->balanceMinor())->toBe(0)
        ->and(BillPayment::query()->where('bill_id', $bill->id)->count())->toBe(2)
        ->and($debitsToPayable)->toBe(100_000 * 100);
});

it('refuses a payment larger than the balance', function () {
    $bill = receivedBill($this, 'VB-6001', 50_000);
    Livewire::actingAs($this->owner)->test(BillsIndex::class)->call('post', $bill->id);

    expect(fn () => app(PayBill::class)->handle($bill->refresh(), 60_000 * 100, PaymentMethod::BankTransfer, $this->owner))
        ->toThrow(ValidationException::class);

    expect($bill->refresh()->paid_minor)->toBe(0);
});

it('voids a bill and reverses its journal, and refuses once money has been paid', function () {
    $bill = receivedBill($this, 'VB-7001', 90_000);
    Livewire::actingAs($this->owner)->test(BillsIndex::class)->call('post', $bill->id);

    Livewire::actingAs($this->owner)
        ->test(BillsIndex::class)
        ->call('openVoid', $bill->id)
        ->set('voidReason', 'Entered against the wrong vendor')
        ->call('voidBill')
        ->assertHasNoErrors();

    $entry = JournalEntry::query()->where('source_type', 'bill')->where('source_id', $bill->id)->sole();

    expect($bill->refresh()->status)->toBe(BillStatus::Void)
        ->and($bill->void_reason)->toBe('Entered against the wrong vendor')
        ->and($entry->refresh()->isReversed())->toBeTrue()
        ->and(JournalEntry::query()->where('reverses_entry_id', $entry->id)->exists())->toBeTrue();

    $paid = receivedBill($this, 'VB-7002', 100_000);
    Livewire::actingAs($this->owner)->test(BillsIndex::class)->call('post', $paid->id);
    app(PayBill::class)->handle($paid->refresh(), 10_000 * 100, PaymentMethod::BankTransfer, $this->owner);

    Livewire::actingAs($this->owner)
        ->test(BillsIndex::class)
        ->call('openVoid', $paid->id)
        ->set('voidReason', 'No longer needed')
        ->call('voidBill')
        ->assertHasErrors('voidReason');

    expect($paid->refresh()->status)->toBe(BillStatus::PartiallyPaid);
});

it('leaves the trial balance balanced after bills and payments', function () {
    $bill = receivedBill($this, 'VB-8001', 75_000, 11_250);
    Livewire::actingAs($this->owner)->test(BillsIndex::class)->call('post', $bill->id);
    app(PayBill::class)->handle($bill->refresh(), 30_000 * 100, PaymentMethod::BankTransfer, $this->owner);

    $this->actingAs($this->owner)
        ->get(route('admin.accounts.trial'))
        ->assertOk()
        ->assertSee('Balanced.')
        ->assertDontSee('Out of balance');
});

it('buckets unpaid bills by how late they are', function () {
    $current = receivedBill($this, 'VB-9001', 10_000);
    $late = receivedBill($this, 'VB-9002', 20_000);
    $late->update(['due_date' => today()->subDays(45)->toDateString()]);

    Livewire::actingAs($this->owner)->test(BillsIndex::class)->call('post', $current->id);
    Livewire::actingAs($this->owner)->test(BillsIndex::class)->call('post', $late->id);

    Livewire::actingAs($this->owner)
        ->test(PayablesAging::class)
        ->assertSee('Not due yet')
        ->assertSee('31–60 days late')
        ->assertSee('VB-9002');
});

it('opens the money-out screens for the owner and refuses a colleague without accounts.view', function () {
    $this->actingAs($this->owner)->get(route('admin.accounts.payables'))->assertOk();
    $this->actingAs($this->owner)->get('/admin/accounts/payables?tab=aging')->assertOk();
    $this->actingAs($this->support)->get(route('admin.accounts.payables'))->assertForbidden();
    $this->actingAs($this->support)->get(route('admin.accounts.profit'))->assertForbidden();
});
