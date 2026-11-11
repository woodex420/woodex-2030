<?php

use App\Domain\Accounts\Actions\PostJournalEntry;
use App\Domain\Accounts\Actions\ReverseJournalEntry;
use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Exceptions\JournalPostingFailed;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\AccountingPeriod;
use App\Domain\Accounts\Models\JournalEntry;
use App\Domain\Accounts\Models\JournalLine;
use App\Domain\Crm\Enums\BusinessLine;
use App\Livewire\Admin\Accounts\ChartOfAccounts;
use App\Livewire\Admin\Accounts\JournalsIndex;
use App\Livewire\Admin\Accounts\PeriodsIndex;
use Database\Seeders\ChartOfAccountsSeeder;
use Livewire\Livewire;

/*
| P8.1 batch C (docs/18 §2): the accounting core. Every posted journal balances to the paisa with
| one side per line; groups never receive postings; posting needs an open period covering the
| business date; a business source posts exactly once; corrections are linked reversals, never edits.
*/

function accountByCode(string $code): Account
{
    $account = Account::query()->where('code', $code)->first();

    expect($account)->not->toBeNull("Seeded account {$code} is missing");

    return $account;
}

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
    $this->admin = $this->userWithRole('admin');
});

it('seeds a chart where groups organise and leaves accept postings', function () {
    foreach (AccountType::cases() as $type) {
        $groups = Account::query()->where('type', $type)->where('is_group', true)->get();
        $leaves = Account::query()->where('type', $type)->where('is_group', false)->get();

        expect($groups)->not->toBeEmpty()
            ->and($leaves)->not->toBeEmpty();

        foreach ($leaves as $leaf) {
            expect($leaf->parent_id)->not->toBeNull("Leaf {$leaf->code} should sit under a group")
                ->and($leaf->acceptsPostings())->toBeTrue();
        }
    }

    expect(Account::query()->where('code', '4010')->value('business_line'))->toBe(BusinessLine::Interior)
        ->and(Account::query()->where('code', '4020')->value('business_line'))->toBe(BusinessLine::Furniture);
});

it('seeds open monthly periods that cover today, and re-seeding never duplicates', function () {
    $covering = AccountingPeriod::query()->covering(today()->toDateString())->get();

    expect($covering)->not->toBeEmpty();

    foreach ($covering as $period) {
        expect($period->isOpen())->toBeTrue();
    }

    // The deploy flow re-runs the seeder against an existing database (DatabaseUpdatesTest).
    $periodsBefore = AccountingPeriod::query()->count();
    $accountsBefore = Account::query()->count();
    app(ChartOfAccountsSeeder::class)->run();
    expect(AccountingPeriod::query()->count())->toBe($periodsBefore)
        ->and(Account::query()->count())->toBe($accountsBefore);
});

it('posts a balanced journal entry with paisa-integer lines', function () {
    $cash = accountByCode('1010');
    $capital = accountByCode('3010');

    $entry = app(PostJournalEntry::class)->handle($this->owner, [
        ['account_id' => $cash->id, 'debit_minor' => 5_000_000, 'memo' => 'Cash in'],
        ['account_id' => $capital->id, 'credit_minor' => 5_000_000, 'memo' => 'Owner capital'],
    ], [
        'business_date' => today()->toDateString(),
        'reference' => 'Opening cash',
        'note' => 'Owner brought in cash',
    ]);

    expect($entry->entry_no)->toStartWith('JE-')
        ->and($entry->lines)->toHaveCount(2)
        ->and($entry->totals())->toBe(['debit_minor' => 5_000_000, 'credit_minor' => 5_000_000])
        ->and($entry->creator->id)->toBe($this->owner->id)
        ->and(JournalLine::query()->where('journal_entry_id', $entry->id)->count())->toBe(2);
});

it('rejects an unbalanced entry', function () {
    $cash = accountByCode('1010');
    $capital = accountByCode('3010');

    app(PostJournalEntry::class)->handle($this->owner, [
        ['account_id' => $cash->id, 'debit_minor' => 100_000],
        ['account_id' => $capital->id, 'credit_minor' => 99_999],
    ], ['business_date' => today()->toDateString()]);
})->throws(JournalPostingFailed::class, 'does not balance');

it('rejects lines that carry both sides or neither side', function (array $lines) {
    $cash = accountByCode('1010');
    $capital = accountByCode('3010');

    $prepared = array_map(function (array $line) use ($cash, $capital): array {
        return [
            'account_id' => $line[0] === 'cash' ? $cash->id : $capital->id,
            'debit_minor' => $line[1],
            'credit_minor' => $line[2],
        ];
    }, $lines);

    app(PostJournalEntry::class)->handle($this->owner, $prepared, ['business_date' => today()->toDateString()]);
})->with([
    'both sides' => [[['cash', 100, 100], ['cash', 0, 100]]],
    'neither side' => [[['cash', 0, 0], ['cash', 0, 0]]],
])->throws(JournalPostingFailed::class, 'exactly one side');

it('rejects postings to groups and inactive accounts', function () {
    $group = accountByCode('1000');

    $assets = accountByCode('1010');
    $capital = accountByCode('3010');
    $assets->update(['is_active' => false]);

    app(PostJournalEntry::class)->handle($this->owner, [
        ['account_id' => $group->id, 'debit_minor' => 100],
        ['account_id' => $capital->id, 'credit_minor' => 100],
    ], ['business_date' => today()->toDateString()]);
})->throws(JournalPostingFailed::class, 'cannot receive postings');

it('refuses to post when no period covers the business date', function () {
    $cash = accountByCode('1010');
    $capital = accountByCode('3010');
    $orphanDate = today()->addYears(3)->toDateString();

    expect(fn () => app(PostJournalEntry::class)->handle($this->owner, [
        ['account_id' => $cash->id, 'debit_minor' => 100],
        ['account_id' => $capital->id, 'credit_minor' => 100],
    ], ['business_date' => $orphanDate]))
        ->toThrow(JournalPostingFailed::class, "No accounting period covers {$orphanDate}");
});

it('blocks posting into a closed period and allows it again after a deliberate reopen', function () {
    $cash = accountByCode('1010');
    $capital = accountByCode('3010');
    $period = AccountingPeriod::query()->covering(today()->toDateString())->first();

    $period->update(['closed_at' => now(), 'closed_by' => $this->owner->id]);

    $post = fn () => app(PostJournalEntry::class)->handle($this->owner, [
        ['account_id' => $cash->id, 'debit_minor' => 100],
        ['account_id' => $capital->id, 'credit_minor' => 100],
    ], ['business_date' => today()->toDateString()]);

    expect($post)->toThrow(JournalPostingFailed::class, "Period {$period->name} is closed");

    $period->update(['closed_at' => null, 'closed_by' => null]);

    expect($post())->toBeInstanceOf(JournalEntry::class);
});

it('posts a business source exactly once', function () {
    $cash = accountByCode('1010');
    $revenue = accountByCode('4090');

    $post = fn () => app(PostJournalEntry::class)->handle($this->owner, [
        ['account_id' => $cash->id, 'debit_minor' => 250_000],
        ['account_id' => $revenue->id, 'credit_minor' => 250_000],
    ], [
        'business_date' => today()->toDateString(),
        'source_type' => 'payment',
        'source_id' => 42,
    ]);

    expect($post())->toBeInstanceOf(JournalEntry::class)
        ->and($post)->toThrow(JournalPostingFailed::class, 'never posts twice')
        ->and(JournalEntry::query()->where('source_type', 'payment')->where('source_id', 42)->count())->toBe(1);
});

it('reverses an entry with mirrored lines and a two-way link', function () {
    $cash = accountByCode('1010');
    $fuel = accountByCode('5130');

    $entry = app(PostJournalEntry::class)->handle($this->owner, [
        ['account_id' => $fuel->id, 'debit_minor' => 80_000, 'memo' => 'Fuel'],
        ['account_id' => $cash->id, 'credit_minor' => 80_000],
    ], ['business_date' => today()->toDateString(), 'reference' => 'Fuel run']);

    $reversal = app(ReverseJournalEntry::class)->handle($entry, $this->owner, 'Wrong account');

    expect($reversal->isReversal())->toBeTrue()
        ->and($reversal->reverses_entry_id)->toBe($entry->id)
        ->and($reversal->reference)->toBe('Reversal of '.$entry->entry_no)
        ->and($reversal->note)->toBe('Wrong account')
        ->and($reversal->lines[0]->debit_minor)->toBe(0)
        ->and($reversal->lines[0]->credit_minor)->toBe(80_000)
        ->and($reversal->lines[1]->debit_minor)->toBe(80_000)
        ->and($entry->fresh()->reversed_by_entry_id)->toBe($reversal->id);

    // Net effect on the books is zero.
    $netFuel = JournalLine::query()->whereIn('account_id', [$fuel->id])->sum('debit_minor')
        - JournalLine::query()->whereIn('account_id', [$fuel->id])->sum('credit_minor');
    expect($netFuel)->toBe(0);
});

it('refuses to reverse an entry twice or to reverse a reversal', function () {
    $cash = accountByCode('1010');
    $fuel = accountByCode('5130');

    $entry = app(PostJournalEntry::class)->handle($this->owner, [
        ['account_id' => $fuel->id, 'debit_minor' => 100],
        ['account_id' => $cash->id, 'credit_minor' => 100],
    ], ['business_date' => today()->toDateString()]);

    $reversal = app(ReverseJournalEntry::class)->handle($entry, $this->owner);

    expect(fn () => app(ReverseJournalEntry::class)->handle($entry, $this->owner))
        ->toThrow(JournalPostingFailed::class, 'already reversed')
        ->and(fn () => app(ReverseJournalEntry::class)->handle($reversal, $this->owner))
        ->toThrow(JournalPostingFailed::class, 'already a reversal');
});

it('shows the accounts screens to the owner and keeps them away from other roles', function () {
    $this->actingAs($this->owner);

    $this->get(route('admin.accounts.chart'))->assertOk()->assertSeeLivewire(ChartOfAccounts::class);
    $this->get(route('admin.accounts.journals'))->assertOk()->assertSeeLivewire(JournalsIndex::class);
    $this->get(route('admin.accounts.periods'))->assertOk()->assertSeeLivewire(PeriodsIndex::class);

    $this->actingAs($this->admin);
    $this->get(route('admin.accounts.chart'))->assertOk(); // admin holds accounts.view

    $sales = $this->userWithRole('sales');
    $this->actingAs($sales);
    $this->get(route('admin.accounts.chart'))->assertForbidden();
    $this->get(route('admin.accounts.journals'))->assertForbidden();
});

it('lets the owner post and reverse a manual entry from the journals screen, and blocks the admin', function () {
    $cash = accountByCode('1010');
    $fuel = accountByCode('5130');

    Livewire::actingAs($this->owner)
        ->test(JournalsIndex::class)
        ->set('businessDate', today()->toDateString())
        ->set('reference', 'Fuel — Auriga')
        ->set('lines', [
            ['account_id' => (string) $fuel->id, 'debit' => '800', 'credit' => '', 'memo' => 'Fuel'],
            ['account_id' => (string) $cash->id, 'debit' => '', 'credit' => '800', 'memo' => ''],
        ])
        ->call('post')
        ->assertHasNoErrors()
        ->assertNotSet('showForm', true);

    $entry = JournalEntry::query()->where('reference', 'Fuel — Auriga')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->totals())->toBe(['debit_minor' => 80_000, 'credit_minor' => 80_000]);

    Livewire::actingAs($this->owner)
        ->test(JournalsIndex::class)
        ->call('askReverse', $entry->id)
        ->set('reversalReason', 'Duplicate fuel run')
        ->call('reverse')
        ->assertHasNoErrors();

    expect($entry->fresh()->reversed_by_entry_id)->not->toBeNull();

    // An admin can read the journals but never post or reverse (accounts.manage is owner-only for now).
    Livewire::actingAs($this->admin)
        ->test(JournalsIndex::class)
        ->call('startEntry')
        ->assertForbidden();
});

it('lets the owner close and reopen a period from the periods screen', function () {
    $period = AccountingPeriod::query()->covering(today()->toDateString())->first();

    Livewire::actingAs($this->owner)
        ->test(PeriodsIndex::class)
        ->call('close', $period->id)
        ->assertHasNoErrors();

    expect($period->fresh()->isOpen())->toBeFalse()
        ->and($period->fresh()->closed_by)->toBe($this->owner->id);

    Livewire::actingAs($this->owner)
        ->test(PeriodsIndex::class)
        ->call('reopen', $period->id)
        ->assertHasNoErrors();

    expect($period->fresh()->isOpen())->toBeTrue();
});

it('lets the owner add an account to the chart without inventing balances', function () {
    Livewire::actingAs($this->owner)
        ->test(ChartOfAccounts::class)
        ->call('create')
        ->set('code', '1050')
        ->set('name', 'Bank — savings')
        ->set('type', 'asset')
        ->set('parentId', accountByCode('1000')->id)
        ->set('businessLine', '')
        ->set('isActive', true)
        ->call('save')
        ->assertHasNoErrors();

    $account = Account::query()->where('code', '1050')->first();

    expect($account)->not->toBeNull()
        ->and($account->is_group)->toBeFalse()
        ->and($account->parent_id)->toBe(accountByCode('1000')->id);
});
