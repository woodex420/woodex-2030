<?php

declare(strict_types=1);

use App\Domain\Accounts\Actions\PostInvoiceToLedger;
use App\Domain\Accounts\Actions\RaiseRecurringExpense;
use App\Domain\Accounts\Actions\RecordExpense;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\AccountingPeriod;
use App\Domain\Accounts\Models\Expense;
use App\Domain\Accounts\Models\JournalEntry;
use App\Domain\Accounts\Models\JournalLine;
use App\Domain\Accounts\Models\RecurringExpense;
use App\Domain\Crm\Models\Contact;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Models\Invoice;
use App\Livewire\Admin\Accounts\ExpensesIndex;
use App\Livewire\Admin\Accounts\OtherIncome;
use App\Livewire\Admin\Accounts\ProfitLoss;
use Livewire\Livewire;

/*
| Slice I — expenses, other income, the repeating overheads and the P&L. Each test asks the same question the
| owner asked: does the screen's number equal what the books actually did?
*/

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
    $this->support = $this->userWithRole('support');

    $this->rent = Account::query()->where('code', '5110')->firstOrFail();
    $this->otherIncome = Account::query()->where('code', '4090')->firstOrFail();
    $this->costOfSales = Account::query()->where('code', '5010')->firstOrFail();
});

function expenseLines(JournalEntry $entry): array
{
    return JournalLine::query()->where('journal_entry_id', $entry->id)->get()
        ->map(fn (JournalLine $line): array => [
            'account' => $line->account_id,
            'debit' => (int) $line->debit_minor,
            'credit' => (int) $line->credit_minor,
        ])->all();
}

it('posts a one-off expense the moment it is saved', function () {
    Livewire::actingAs($this->owner)
        ->test(ExpensesIndex::class)
        ->call('openForm')
        ->set('description', 'Studio electricity — October')
        ->set('amountRupees', '12500')
        ->set('accountId', $this->rent->id)
        ->set('spentOn', today()->toDateString())
        ->set('paidFrom', 'cash')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Studio electricity — October');

    $expense = Expense::query()->where('description', 'Studio electricity — October')->sole();
    $entry = JournalEntry::query()->where('source_type', 'expense')->where('source_id', $expense->id)->sole();
    $lines = expenseLines($entry);

    expect((int) $expense->amount_minor)->toBe(12500 * 100)
        ->and($expense->journal_entry_id)->toBe($entry->id)
        ->and($lines)->toHaveCount(2)
        ->and($lines[0]['account'])->toBe($this->rent->id)
        ->and($lines[0]['debit'])->toBe(12500 * 100)
        ->and($lines[1]['debit'])->toBe(0)
        ->and($lines[1]['credit'])->toBe(12500 * 100);
});

it('books other income the other way round and keeps income accounts out of the expense form', function () {
    Livewire::actingAs($this->owner)
        ->test(OtherIncome::class)
        ->call('openForm')
        ->set('description', 'Scrap timber sold')
        ->set('amountRupees', '3000')
        ->set('accountId', $this->otherIncome->id)
        ->set('spentOn', today()->toDateString())
        ->set('paidFrom', 'bank')
        ->call('save')
        ->assertHasNoErrors();

    $expense = Expense::query()->where('description', 'Scrap timber sold')->sole();
    $entry = JournalEntry::query()->where('source_type', 'expense')->where('source_id', $expense->id)->sole();
    $lines = expenseLines($entry);

    expect($expense->direction->value)->toBe('in')
        ->and($lines[0]['debit'])->toBe(3000 * 100)   // bank debited
        ->and($lines[1]['credit'])->toBe(3000 * 100)  // income credited
        ->and($lines[1]['account'])->toBe($this->otherIncome->id);

    Livewire::actingAs($this->owner)
        ->test(ExpensesIndex::class)
        ->call('openForm')
        ->set('description', 'Should be refused')
        ->set('amountRupees', '100')
        ->set('accountId', $this->otherIncome->id)
        ->set('spentOn', today()->toDateString())
        ->call('save');

    expect(Expense::query()->where('description', 'Should be refused')->exists())->toBeFalse();
});

it('raises a recurring overhead once per period and moves the date from itself', function () {
    $schedule = RecurringExpense::query()->create([
        'description' => 'Studio rent',
        'amount_minor' => 20_000 * 100,
        'account_id' => $this->rent->id,
        'paid_from' => 'bank',
        'frequency' => 'monthly',
        'starts_on' => today()->toDateString(),
        'next_run_on' => today()->toDateString(),
        'is_active' => true,
        'created_by' => $this->owner->id,
    ]);

    $first = app(RaiseRecurringExpense::class)->handle($schedule, $this->owner);
    $second = app(RaiseRecurringExpense::class)->handle($schedule->refresh(), $this->owner);

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and(Expense::query()->where('recurring_expense_id', $schedule->id)->count())->toBe(1)
        ->and($schedule->refresh()->next_run_on->toDateString())->toBe(today()->addMonthNoOverflow()->toDateString())
        ->and($schedule->raised_count)->toBe(1)
        ->and(JournalEntry::query()->where('source_type', 'expense')->count())->toBe(1);
});

it('posts due schedules from the command, skips the dry run, and stops a schedule past its end', function () {
    $due = RecurringExpense::query()->create([
        'description' => 'Internet',
        'amount_minor' => 4_500 * 100,
        'account_id' => $this->rent->id,
        'paid_from' => 'bank',
        'frequency' => 'monthly',
        'starts_on' => today()->subDays(2)->toDateString(),
        'next_run_on' => today()->subDays(2)->toDateString(),
        'ends_on' => today()->subDay()->toDateString(),
        'is_active' => true,
        'created_by' => $this->owner->id,
    ]);

    expect($this->artisan('woodex:expense-recurring', ['--dry-run' => true]))->toBe(0);
    expect(Expense::query()->count())->toBe(0);

    expect($this->artisan('woodex:expense-recurring'))->toBe(0);

    expect(Expense::query()->count())->toBe(1)
        ->and($due->refresh()->is_active)->toBeFalse()
        ->and($due->expenses()->count())->toBe(1);
});

it('shows the P&L from posted lines only and says when nothing is posted', function () {
    Livewire::actingAs($this->owner)
        ->test(ProfitLoss::class)
        ->assertSee('Nothing posted yet')
        ->assertDontSee('Profit Rs');

    // A client invoice posts revenue through the same ledger the P&L reads.
    $invoice = Invoice::query()->create([
        'number' => 'T-PL-1',
        'kind' => 'manual',
        'status' => InvoiceStatus::Issued->value,
        'contact_id' => Contact::factory()->create()->id,
        'business_line' => 'interior',
        'currency' => 'PKR',
        'subtotal_minor' => 200_000 * 100,
        'discount_minor' => 0,
        'tax_minor' => 0,
        'total_minor' => 200_000 * 100,
        'paid_minor' => 0,
        'balance_minor' => 200_000 * 100,
        'issue_date' => today()->toDateString(),
        'due_date' => today()->addDays(7)->toDateString(),
    ]);
    app(PostInvoiceToLedger::class)->handle($invoice, $this->owner);

    Livewire::actingAs($this->owner)
        ->test(ExpensesIndex::class)
        ->call('openForm')
        ->set('description', 'Timber for the kitchen job')
        ->set('amountRupees', '80000')
        ->set('accountId', $this->costOfSales->id)
        ->set('spentOn', today()->toDateString())
        ->set('paidFrom', 'bank')
        ->call('save');

    Livewire::actingAs($this->owner)
        ->test(ProfitLoss::class)
        ->assertSee('Interior design revenue')
        ->assertSee('Materials & joinery cost')
        ->assertSee('Gross profit')
        ->assertSee('Net profit');

    $entry = JournalEntry::query()->where('source_type', 'expense')->sole();
    $lines = expenseLines($entry);

    expect($lines[0]['account'])->toBe($this->costOfSales->id);
});

it('opens the ledger screens only for accounts.view', function () {
    $this->actingAs($this->owner)->get(route('admin.accounts.expenses'))->assertOk();
    $this->actingAs($this->owner)->get('/admin/accounts/expenses?tab=recurring')->assertOk();
    $this->actingAs($this->support)->get(route('admin.accounts.expenses'))->assertForbidden();
});

it('refuses to post an expense when the period is closed', function () {
    $period = AccountingPeriod::query()->covering(today()->toDateString())->firstOrFail();
    $period->update(['status' => 'closed', 'closed_at' => now()]);

    Livewire::actingAs($this->owner)
        ->test(ExpensesIndex::class)
        ->call('openForm')
        ->set('description', 'Should not post')
        ->set('amountRupees', '1000')
        ->set('accountId', $this->rent->id)
        ->set('spentOn', today()->toDateString())
        ->set('paidFrom', 'cash')
        ->call('save');

    expect(Expense::query()->where('description', 'Should not post')->exists())->toBeFalse();
});

it('totals the whole filtered range even when the detail list is capped', function (string $component, string $direction, string $mainCode, string $olderCode, string $oppositeCode) {
    $record = app(RecordExpense::class);
    $mainId = Account::query()->where('code', $mainCode)->sole()->id;
    $olderId = Account::query()->where('code', $olderCode)->sole()->id;
    $oppositeId = Account::query()->where('code', $oppositeCode)->sole()->id;
    $today = today()->toDateString();
    $yesterday = today()->subDay()->toDateString();
    $attributes = [
        'direction' => $direction,
        'account_id' => $mainId,
        'amount_minor' => 12501,
        'spent_on' => $today,
        'paid_from' => 'cash',
        'description' => 'Synthetic range regression',
    ];

    // This account appears only in the older, 301st row: both its category and amount must survive the cap.
    $record->handle(array_replace($attributes, ['account_id' => $olderId, 'amount_minor' => 701, 'spent_on' => $yesterday]), $this->owner);
    for ($i = 0; $i < 300; $i++) {
        $record->handle($attributes, $this->owner);
    }
    $record->handle(array_replace($attributes, ['amount_minor' => 99999, 'spent_on' => today()->subDays(2)->toDateString()]), $this->owner);
    $record->handle(array_replace($attributes, ['direction' => $direction === 'out' ? 'in' : 'out', 'account_id' => $oppositeId]), $this->owner);

    $assertGroups = fn ($groups): bool => $groups->pluck('total')->sort()->values()->all() === [701, 3750300];
    Livewire::actingAs($this->owner)->test($component)
        ->set('filterFrom', $yesterday)->set('filterTo', $today)
        ->assertViewHas('rows', fn ($rows): bool => $rows->count() === 300)
        ->assertViewHas('total', 3751001)
        ->assertViewHas('byAccount', $assertGroups)
        // Reversed dates use the same bounds for the detail list and the complete aggregates.
        ->set('filterFrom', $today)->set('filterTo', $yesterday)
        ->assertViewHas('total', 3751001)
        ->assertViewHas('byAccount', $assertGroups)
        ->set('filterFrom', today()->addDays(2)->toDateString())
        ->set('filterTo', today()->addDays(3)->toDateString())
        ->assertViewHas('total', 0)
        ->assertViewHas('byAccount', fn ($groups): bool => $groups->isEmpty());
})->with([
    'expenses' => [ExpensesIndex::class, 'out', '5110', '5010', '4090'],
    'other income' => [OtherIncome::class, 'in', '4090', '4010', '5110'],
]);
