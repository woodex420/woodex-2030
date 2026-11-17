<?php

declare(strict_types=1);

use App\Domain\Accounts\Actions\PayBill;
use App\Domain\Accounts\Actions\PostBillToLedger;
use App\Domain\Accounts\Actions\PostProjectExpense;
use App\Domain\Accounts\Actions\ReceiveBill;
use App\Domain\Accounts\Actions\RecordExpense;
use App\Domain\Accounts\Actions\SaveBill;
use App\Domain\Accounts\Actions\VoidBill;
use App\Domain\Accounts\Enums\BillStatus;
use App\Domain\Accounts\Exceptions\JournalPostingFailed;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\Bill;
use App\Domain\Accounts\Models\BillPayment;
use App\Domain\Accounts\Models\Expense;
use App\Domain\Accounts\Models\JournalEntry;
use App\Domain\Accounts\Models\JournalLine;
use App\Domain\Accounts\Models\Vendor;
use App\Domain\Crm\Models\Contact;
use App\Domain\Projects\Actions\RecordProjectCost;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectExpense;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Livewire\Admin\Accounts\BillsIndex;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
    $this->vendor = Vendor::query()->create(['name' => 'Synthetic supplier', 'is_active' => true]);
    $this->project = Project::query()->create([
        'code' => 'TEST-LINK-1', 'name' => 'Synthetic cost project',
        'contact_id' => Contact::factory()->create()->id, 'business_line' => 'interior',
    ]);
    $this->materials = Account::query()->where('code', '5010')->firstOrFail();
    $this->cost = [
        'kind' => 'bill', 'vendor_id' => $this->vendor->id, 'account_id' => $this->materials->id,
        'amount_minor' => 1250050, 'description' => 'Synthetic sheets', 'reference' => 'TEST-SUP-001',
        'spent_on' => today()->toDateString(), 'paid_from' => 'bank',
    ];
});

it('records one linked source and one balanced project-tagged cost journal', function (string $kind) {
    $this->cost['kind'] = $kind;
    $source = app(RecordProjectCost::class)->handle($this->project, $this->cost, $this->owner, (string) Str::uuid());
    $entry = $source->journalEntry()->firstOrFail();
    expect($source)->toBeInstanceOf($kind === 'bill' ? Bill::class : Expense::class)
        ->and($source->project_id)->toBe($this->project->id)
        ->and($source->project()->firstOrFail()->id)->toBe($this->project->id)
        ->and($entry->project_id)->toBe($this->project->id)
        ->and($entry->business_line->value)->toBe('interior')
        ->and($entry->totals())->toBe(['debit_minor' => 1250050, 'credit_minor' => 1250050])
        ->and(Bill::query()->count())->toBe($kind === 'bill' ? 1 : 0)
        ->and(Expense::query()->count())->toBe($kind === 'paid' ? 1 : 0)
        ->and(ProjectExpense::query()->count())->toBe(0)
        ->and(DB::table('project_cost_requests')->count())->toBe(1);
    $creditAccount = Account::query()->where('code', $kind === 'bill' ? '2010' : '1020')->value('id');
    expect((int) $entry->lines()->where('account_id', $creditAccount)->value('credit_minor'))->toBe(1250050);
})->with(['bill', 'paid']);

it('replays the same cost request without writing a second source or journal', function (string $kind) {
    $this->cost['kind'] = $kind;
    $key = (string) Str::uuid();
    $first = app(RecordProjectCost::class)->handle($this->project, $this->cost, $this->owner, $key);
    $again = app(RecordProjectCost::class)->handle($this->project, $this->cost, $this->owner, strtoupper($key));
    expect($again->id)->toBe($first->id)->and(get_class($again))->toBe(get_class($first))
        ->and(JournalEntry::query()->count())->toBe(1)->and(DB::table('project_cost_requests')->count())->toBe(1);
})->with(['bill', 'paid']);

it('rejects changed payload, source kind or project on an already used request', function (string $change) {
    $key = (string) Str::uuid();
    app(RecordProjectCost::class)->handle($this->project, $this->cost, $this->owner, $key);
    if ($change === 'kind') {
        $this->cost['kind'] = 'paid';
    } elseif ($change === 'amount') {
        $this->cost['amount_minor']++;
    } else {
        $this->project = Project::query()->create([
            'code' => 'TEST-LINK-2', 'name' => 'Second synthetic project',
            'contact_id' => $this->project->contact_id, 'business_line' => 'furniture',
        ]);
    }
    expect(fn () => app(RecordProjectCost::class)->handle($this->project, $this->cost, $this->owner, $key))->toThrow(ValidationException::class);
    expect(Bill::query()->count())->toBe(1)->and(Expense::query()->count())->toBe(0)->and(JournalEntry::query()->count())->toBe(1);
})->with(['kind', 'amount', 'project']);

it('rejects a second booking of the same supplier document in either entry path', function (string $firstKind) {
    $this->cost['kind'] = $firstKind;
    app(RecordProjectCost::class)->handle($this->project, $this->cost, $this->owner, (string) Str::uuid());
    $this->cost['kind'] = $firstKind === 'bill' ? 'paid' : 'bill';
    expect(fn () => app(RecordProjectCost::class)->handle($this->project, $this->cost, $this->owner, (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(JournalEntry::query()->count())->toBe(1)->and(DB::table('project_cost_requests')->count())->toBe(1);
})->with(['bill', 'paid']);

it('also guards the existing general expense and bill screens against cross-model duplicates', function () {
    $bill = app(RecordProjectCost::class)->handle($this->project, $this->cost, $this->owner, (string) Str::uuid());
    expect(fn () => app(RecordExpense::class)->handle([
        'direction' => 'out', 'vendor_id' => $this->vendor->id, 'account_id' => $this->materials->id,
        'amount_minor' => 1250050, 'description' => 'Duplicate', 'reference' => ' TEST-SUP-001 ',
        'spent_on' => today()->toDateString(), 'paid_from' => 'bank',
    ], $this->owner))->toThrow(ValidationException::class);
    Livewire::actingAs($this->owner)->test(BillsIndex::class)->call('openForm')
        ->set('vendorId', $this->vendor->id)->set('number', $bill->number)
        ->set('lines', [['accountId' => $this->materials->id, 'description' => 'Duplicate', 'amountRupees' => '12500.50']])
        ->call('saveReceived')->assertHasErrors('number');
    expect(JournalEntry::query()->count())->toBe(1)->and(Expense::query()->count())->toBe(0);
});

it('clears only the payable and does not recognize the cost again when a bill is paid', function () {
    $bill = app(RecordProjectCost::class)->handle($this->project, $this->cost, $this->owner, (string) Str::uuid());
    $pay = app(PayBill::class)->handle($bill, 500025, PaymentMethod::BankTransfer, $this->owner, 'TEST-PAY-1', idempotencyKey: (string) Str::uuid());
    $entry = $pay->journalEntry()->firstOrFail();
    expect($entry->source_type)->toBe('bill_payment')->and($entry->source_id)->toBe($pay->id)
        ->and($entry->project_id)->toBe($this->project->id)->and($entry->business_line->value)->toBe('interior')
        ->and($entry->totals())->toBe(['debit_minor' => 500025, 'credit_minor' => 500025])
        ->and($entry->lines()->where('account_id', $this->materials->id)->exists())->toBeFalse()
        ->and($bill->refresh()->balanceMinor())->toBe(750025)
        ->and($bill->status)->toBe(BillStatus::PartiallyPaid)
        ->and((int) JournalLine::query()->where('account_id', $this->materials->id)->sum('debit_minor'))->toBe(1250050)
        ->and(Expense::query()->count())->toBe(0)->and(ProjectExpense::query()->count())->toBe(0);
});

it('replays a full payment even after the bill became paid and refuses changed retry details', function () {
    $bill = app(RecordProjectCost::class)->handle($this->project, $this->cost, $this->owner, (string) Str::uuid());
    $key = (string) Str::uuid();
    $first = app(PayBill::class)->handle($bill, 1250050, PaymentMethod::BankTransfer, $this->owner, '0', idempotencyKey: $key);
    $again = app(PayBill::class)->handle($bill, 1250050, PaymentMethod::BankTransfer, $this->owner, '0', idempotencyKey: strtoupper($key));
    expect($again->id)->toBe($first->id)->and($first->reference)->toBe('0')
        ->and($bill->refresh()->status)->toBe(BillStatus::Paid)->and(BillPayment::query()->count())->toBe(1)
        ->and(JournalEntry::query()->count())->toBe(2);
    expect(fn () => app(PayBill::class)->handle($bill, 1, PaymentMethod::BankTransfer, $this->owner, '0', idempotencyKey: $key))->toThrow(ValidationException::class);
});

it('rejects an overpayment without leaving a payment row or journal', function () {
    $bill = app(RecordProjectCost::class)->handle($this->project, $this->cost, $this->owner, (string) Str::uuid());
    expect(fn () => app(PayBill::class)->handle($bill, 1250051, PaymentMethod::BankTransfer, $this->owner, idempotencyKey: (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(BillPayment::query()->count())->toBe(0)->and(JournalEntry::query()->count())->toBe(1)
        ->and($bill->refresh()->paid_minor)->toBe(0);
});

it('rechecks current authority instead of trusting a stale owner instance', function () {
    $this->owner->load('roles');
    $stale = clone $this->owner;
    $this->owner->syncRoles(['support']);
    expect(fn () => app(RecordProjectCost::class)->handle($this->project, $this->cost, $stale, (string) Str::uuid()))->toThrow(AuthorizationException::class);
    expect(Bill::query()->count())->toBe(0)->and(DB::table('project_cost_requests')->count())->toBe(0);
});

it('refuses inactive actors and deleted projects before creating any financial record', function (string $reason) {
    if ($reason === 'actor') {
        $this->owner->update(['is_active' => false]);
        $exception = AuthorizationException::class;
    } else {
        $this->project->delete();
        $exception = ValidationException::class;
    }
    expect(fn () => app(RecordProjectCost::class)->handle($this->project, $this->cost, $this->owner, (string) Str::uuid()))->toThrow($exception);
    expect(Bill::query()->count())->toBe(0)->and(JournalEntry::query()->count())->toBe(0);
})->with(['actor', 'project']);

it('allows payment of an already linked liability after its project was archived', function () {
    $bill = app(RecordProjectCost::class)->handle($this->project, $this->cost, $this->owner, (string) Str::uuid());
    $this->project->delete();
    $payment = app(PayBill::class)->handle($bill, 1250050, PaymentMethod::BankTransfer, $this->owner);
    expect($payment->journalEntry()->firstOrFail()->project_id)->toBe($this->project->id)
        ->and($bill->refresh()->status)->toBe(BillStatus::Paid);
});

it('rolls back both request reservation and source if posting fails', function () {
    $this->materials->update(['is_active' => false]);
    $key = (string) Str::uuid();
    expect(fn () => app(RecordProjectCost::class)->handle($this->project, $this->cost, $this->owner, $key))->toThrow(JournalPostingFailed::class);
    expect(Bill::query()->count())->toBe(0)->and(JournalEntry::query()->count())->toBe(0)
        ->and(DB::table('project_cost_requests')->count())->toBe(0);
    $this->materials->update(['is_active' => true]);
    expect(app(RecordProjectCost::class)->handle($this->project, $this->cost, $this->owner, $key))->toBeInstanceOf(Bill::class);
});

it('refuses to overwrite a posted bill through a form that was opened while it was a draft', function () {
    $screen = Livewire::actingAs($this->owner)->test(BillsIndex::class)->call('openForm')
        ->set('vendorId', $this->vendor->id)->set('number', 'TEST-STALE')
        ->set('lines', [['accountId' => $this->materials->id, 'description' => 'Original', 'amountRupees' => '12500.50']])
        ->call('saveDraft')->assertHasNoErrors();
    $bill = Bill::query()->sole();
    $screen->call('openForm', $bill->id);
    app(ReceiveBill::class)->handle($bill, $this->owner);
    $screen->set('lines.0.amountRupees', '1')->call('saveDraft')->assertHasErrors('bill');
    expect($bill->refresh()->status)->toBe(BillStatus::Received)->and($bill->total_minor)->toBe(1250050)
        ->and($bill->items()->sole()->amount_minor)->toBe(1250050)->and(JournalEntry::query()->count())->toBe(1);
});

it('rechecks a stale draft instance under lock when asked to post again', function () {
    $bill = app(SaveBill::class)->handle([
        'vendor_id' => $this->vendor->id, 'number' => 'TEST-RECEIVE', 'bill_date' => today()->toDateString(),
    ], [['account_id' => $this->materials->id, 'description' => 'Original', 'amount_minor' => 1250050]], $this->owner);
    $stale = clone $bill;
    app(ReceiveBill::class)->handle($bill, $this->owner);
    expect(fn () => app(ReceiveBill::class)->handle($stale, $this->owner))->toThrow(ValidationException::class);
    $entry = app(PostBillToLedger::class)->handle($stale, $this->owner);
    expect($entry->id)->toBe($bill->refresh()->journal_entry_id)->and($bill->status)->toBe(BillStatus::Received)
        ->and(JournalEntry::query()->count())->toBe(1);
});

it('voids an unpaid linked bill by reversal while preserving request identity and project context', function () {
    $key = (string) Str::uuid();
    $bill = app(RecordProjectCost::class)->handle($this->project, $this->cost, $this->owner, $key);
    app(VoidBill::class)->handle($bill, $this->owner, 'Synthetic wrong invoice');
    $again = app(RecordProjectCost::class)->handle($this->project, $this->cost, $this->owner, $key);
    expect($again->id)->toBe($bill->id)->and($again->status)->toBe(BillStatus::Void)
        ->and(JournalEntry::query()->count())->toBe(2)
        ->and(JournalEntry::query()->whereNotNull('reverses_entry_id')->sole()->project_id)->toBe($this->project->id)
        ->and(Expense::query()->count())->toBe(0);
});

it('never infers or copies legacy project costs into bills or expenses', function () {
    $legacy = ProjectExpense::query()->create([
        'project_id' => $this->project->id, 'category' => 'material', 'description' => 'Legacy synthetic cost',
        'amount_minor' => 2500, 'incurred_on' => today()->toDateString(), 'created_by' => $this->owner->id,
    ]);
    app(PostProjectExpense::class)->handle($legacy, $this->owner);
    $snapshot = $legacy->refresh()->getAttributes();
    app(RecordProjectCost::class)->handle($this->project, $this->cost, $this->owner, (string) Str::uuid());
    expect($legacy->refresh()->getAttributes())->toBe($snapshot)->and(ProjectExpense::query()->count())->toBe(1)
        ->and(Bill::query()->count())->toBe(1)->and(JournalEntry::query()->count())->toBe(2);
});

it('rejects payable or incoming expense shortcuts when linked to a project', function (string $mode) {
    expect(fn () => app(RecordExpense::class)->handle([
        'project_id' => $this->project->id, 'direction' => $mode === 'incoming' ? 'in' : 'out',
        'account_id' => $this->materials->id, 'amount_minor' => 1250050, 'description' => 'Wrong shortcut',
        'spent_on' => today()->toDateString(), 'paid_from' => $mode === 'incoming' ? 'bank' : 'payable',
    ], $this->owner))->toThrow(ValidationException::class);
    expect(Expense::query()->count())->toBe(0)->and(JournalEntry::query()->count())->toBe(0);
})->with(['incoming', 'payable']);

it('rejects integer overflow instead of persisting float-derived bill totals', function () {
    expect(fn () => app(SaveBill::class)->handle([
        'vendor_id' => $this->vendor->id, 'number' => 'TEST-OVERFLOW', 'bill_date' => today()->toDateString(),
        'tax_minor' => 1,
    ], [['account_id' => $this->materials->id, 'description' => 'Too large', 'amount_minor' => PHP_INT_MAX]], $this->owner))->toThrow(ValidationException::class);
    expect(Bill::query()->count())->toBe(0);
});

it('requires project visibility in addition to financial management authority', function () {
    $actor = $this->userWithRole('support');
    $actor->syncRoles([]);
    $actor->syncPermissions(['accounts.manage']);
    expect(fn () => app(RecordProjectCost::class)->handle($this->project, $this->cost, $actor, (string) Str::uuid()))->toThrow(AuthorizationException::class);
    expect(Bill::query()->count())->toBe(0)->and(DB::table('project_cost_requests')->count())->toBe(0);
});

it('validates paid cost input before reserving a request or writing money', function (string $field, mixed $value) {
    $this->cost['kind'] = 'paid';
    $this->cost[$field] = $value;
    expect(fn () => app(RecordProjectCost::class)->handle($this->project, $this->cost, $this->owner, (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(Bill::query()->count())->toBe(0)->and(Expense::query()->count())->toBe(0)
        ->and(JournalEntry::query()->count())->toBe(0)->and(DB::table('project_cost_requests')->count())->toBe(0);
})->with([
    ['amount_minor', 0], ['amount_minor', -1], ['amount_minor', '12.50'],
    ['reference', ' '], ['vendor_id', -1], ['spent_on', 'not-a-date'],
    ['paid_from', 'payable'], ['paid_from', null], ['kind', 'unknown'],
]);

it('allows the same document reference from a different vendor without inventing a duplicate', function () {
    app(RecordProjectCost::class)->handle($this->project, $this->cost, $this->owner, (string) Str::uuid());
    $this->cost['vendor_id'] = Vendor::query()->create(['name' => 'Second synthetic supplier', 'is_active' => true])->id;
    $this->cost['kind'] = 'paid';
    app(RecordProjectCost::class)->handle($this->project, $this->cost, $this->owner, (string) Str::uuid());
    expect(Bill::query()->count())->toBe(1)->and(Expense::query()->count())->toBe(1)->and(JournalEntry::query()->count())->toBe(2);
});

it('preserves a linked draft project when the existing bill screen saves without a project field', function () {
    $bill = app(SaveBill::class)->handle([
        'vendor_id' => $this->vendor->id, 'number' => 'TEST-DRAFT-LINK',
        'bill_date' => today()->toDateString(), 'project_id' => $this->project->id,
    ], [['account_id' => $this->materials->id, 'description' => 'Draft', 'amount_minor' => 1250050]], $this->owner);
    Livewire::actingAs($this->owner)->test(BillsIndex::class)->call('openForm', $bill->id)
        ->set('lines.0.amountRupees', '500.25')->call('saveReceived')->assertHasNoErrors();
    expect($bill->refresh()->project_id)->toBe($this->project->id)->and($bill->total_minor)->toBe(50025)
        ->and($bill->journalEntry()->firstOrFail()->project_id)->toBe($this->project->id);
});

it('adds nullable links on upgrade without changing existing source rows, payments or journals', function () {
    $bill = app(SaveBill::class)->handle([
        'vendor_id' => $this->vendor->id, 'number' => 'TEST-PRE-LINK', 'bill_date' => today()->toDateString(),
    ], [['account_id' => $this->materials->id, 'description' => 'Existing bill', 'amount_minor' => 1250050]], $this->owner, BillStatus::Received);
    app(PayBill::class)->handle($bill, 500025, PaymentMethod::BankTransfer, $this->owner);
    app(RecordExpense::class)->handle([
        'direction' => 'out', 'account_id' => $this->materials->id, 'amount_minor' => 2500,
        'description' => 'Existing expense', 'spent_on' => today()->toDateString(), 'paid_from' => 'cash',
    ], $this->owner);

    // Isolate DDL from RefreshDatabase's transaction (MySQL DDL implicitly commits).
    // The original connection's financial fixtures are read only during this upgrade check.
    config(['database.connections.cost_upgrade' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
    ]]);
    $upgrade = DB::connection('cost_upgrade');
    $schema = $upgrade->getSchemaBuilder();
    $originalSchema = Schema::getFacadeRoot();
    $tables = ['bills', 'bill_items', 'bill_payments', 'expenses', 'journal_entries', 'journal_lines', 'project_expenses'];
    $snapshot = [];
    try {
        $schema->create('projects', fn ($table) => $table->id());
        $upgrade->table('projects')->insert(['id' => $this->project->id]);
        foreach ($tables as $table) {
            $rows = DB::table($table)->orderBy('id')->get()->map(function ($row) use ($table): array {
                $data = (array) $row;
                if (in_array($table, ['bills', 'expenses'], true)) {
                    unset($data['project_id']);
                }
                if ($table === 'bill_payments') {
                    unset($data['idempotency_key']);
                }

                return $data;
            })->all();
            // Preserve all populated historical financial columns; only the newly added links are absent.
            $schema->create($table, function ($blueprint) use ($rows): void {
                $blueprint->id();
                foreach (array_keys($rows[0] ?? []) as $column) {
                    if ($column !== 'id') {
                        if (str_ends_with($column, '_minor') || str_ends_with($column, '_id')) {
                            $blueprint->bigInteger($column)->nullable();
                        } else {
                            $blueprint->text($column)->nullable();
                        }
                    }
                }
            });
            if ($rows !== []) {
                $upgrade->table($table)->insert($rows);
            }
            $snapshot[$table] = $upgrade->table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        Schema::swap($schema);
        $migration = require database_path('migrations/2026_11_02_180000_link_project_cost_sources.php');
        $migration->up();
        foreach ($tables as $table) {
            $after = $upgrade->table($table)->orderBy('id')->get()->map(function ($row) use ($table): array {
                $data = (array) $row;
                if (in_array($table, ['bills', 'expenses'], true)) {
                    expect($data['project_id'])->toBeNull();
                    unset($data['project_id']);
                }
                if ($table === 'bill_payments') {
                    expect($data['idempotency_key'])->toBeNull();
                    unset($data['idempotency_key']);
                }

                return $data;
            })->all();
            expect($after)->toBe($snapshot[$table]);
        }
        expect($upgrade->table('project_cost_requests')->count())->toBe(0);
        expect($upgrade->select('PRAGMA foreign_key_check'))->toBe([]);
        $migration->down();
        expect($schema->hasColumn('expenses', 'project_id'))->toBeFalse();
        expect($schema->hasColumn('bills', 'project_id'))->toBeFalse();
        expect($schema->hasColumn('bill_payments', 'idempotency_key'))->toBeFalse();
    } finally {
        Schema::swap($originalSchema);
        DB::purge('cost_upgrade');
    }
});

it('rejects an income or liability account as a supplier bill cost', function (string $code) {
    $this->cost['account_id'] = Account::query()->where('code', $code)->value('id');
    expect(fn () => app(RecordProjectCost::class)->handle($this->project, $this->cost, $this->owner, (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(Bill::query()->count())->toBe(0)->and(JournalEntry::query()->count())->toBe(0)
        ->and(DB::table('project_cost_requests')->count())->toBe(0);
})->with(['2010', '4010']);

it('checks current authority on the ledger posting action as well as the bill writers', function () {
    $bill = app(SaveBill::class)->handle([
        'vendor_id' => $this->vendor->id, 'number' => 'TEST-POST-AUTH', 'bill_date' => today()->toDateString(),
    ], [['account_id' => $this->materials->id, 'description' => 'Draft', 'amount_minor' => 1250050]], $this->owner);
    $bill->update(['status' => BillStatus::Received]);
    $support = $this->userWithRole('support');
    expect(fn () => app(PostBillToLedger::class)->handle($bill, $support))->toThrow(AuthorizationException::class);
    expect(JournalEntry::query()->count())->toBe(0);
});
