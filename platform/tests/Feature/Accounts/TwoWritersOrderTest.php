<?php

declare(strict_types=1);

use App\Domain\Accounts\Actions\PostJournalEntry;
use App\Domain\Accounts\Actions\RecordExpense;
use App\Domain\Accounts\Actions\SaveBill;
use App\Domain\Accounts\Enums\BillStatus;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\Vendor;
use App\Domain\Accounts\Services\LedgerTurn;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Sales\Actions\CollectPayment;
use App\Domain\Sales\Actions\RecordPayment;
use App\Domain\Sales\Actions\VerifyPayment;
use App\Domain\Sales\Enums\InvoiceKind;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Enums\PaymentStatus;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Services\InvoiceLocks;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| Master list T-093: two people writing to the ledger at the same moment.
|
| What happens when two requests meet cannot be shown in this suite: it calls one action after another inside one
| transaction, and SQLite lets one writer in at a time. It is tried by tools/ci/two-writers.php with two processes
| on MySQL and MariaDB (CI job "Two writers"). What that run found was a matter of ORDER, which row is locked
| before which, and was cured by order. These tests keep the order: each fails when a line goes back to where the
| fault was, on any database, long before two people meet.
*/

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    Notification::fake();
    config(['documents.test_bypass' => true]);
    $this->owner = $this->userWithRole('owner');
    $this->client = Contact::factory()->create();
});

/**
 * Every statement the work sends to the database, in order, with what it was asked for.
 *
 * @return list<array{0: string, 1: array<int, mixed>}>
 */
function statementsOf(Closure $work): array
{
    $seen = [];
    $listening = true;
    DB::listen(function (QueryExecuted $query) use (&$seen, &$listening): void {
        if ($listening) {
            $seen[] = [strtolower(str_replace(['"', '`'], '', $query->sql)), $query->bindings];
        }
    });
    $work();
    $listening = false;

    return $seen;
}

/** Where the first statement with these words stands, or null. */
function firstWith(array $statements, string ...$words): ?int
{
    foreach ($statements as $position => [$sql]) {
        if (array_filter($words, fn (string $word): bool => ! str_contains($sql, $word)) === []) {
            return $position;
        }
    }

    return null;
}

function orderInvoice(Contact $client, int $total = 10_000, InvoiceStatus $status = InvoiceStatus::Issued): Invoice
{
    static $number = 0;

    $invoice = Invoice::query()->create([
        'number' => 'TEST-ORDER-'.++$number, 'contact_id' => $client->id, 'business_line' => BusinessLine::Furniture,
        'kind' => InvoiceKind::Manual, 'status' => $status, 'currency' => 'PKR', 'subtotal_minor' => $total, 'total_minor' => $total,
        'paid_minor' => $status === InvoiceStatus::Paid ? $total : 0, 'balance_minor' => $status === InvoiceStatus::Paid ? 0 : $total,
        'issue_date' => today(), 'due_date' => today()->addDays(10),
    ]);
    $invoice->items()->create(['position' => 0, 'description' => 'Order', 'unit' => 'job', 'qty' => '1', 'rate_minor' => $total, 'line_total_minor' => $total]);

    return $invoice;
}

/** The invoices read one by one by their own number, in the order they were read, up to a given statement. */
function invoicesTakenBefore(array $statements, ?int $before): array
{
    $taken = [];
    foreach ($statements as $position => [$sql, $bindings]) {
        if ($before !== null && $position >= $before) {
            break;
        }
        if (str_starts_with($sql, 'select * from invoices where invoices.id = ?') && str_contains($sql, 'limit 1')) {
            $taken[] = (int) $bindings[0];
        }
    }

    return $taken;
}

it('takes the ledger\'s turn before it asks the journal or the periods anything', function () {
    $statements = statementsOf(fn () => app(PostJournalEntry::class)->handle($this->owner, [
        ['account_id' => Account::query()->where('code', '1010')->value('id'), 'debit_minor' => 5_000],
        ['account_id' => Account::query()->where('code', '3010')->value('id'), 'credit_minor' => 5_000],
    ], ['source_type' => 'order-test', 'source_id' => 1, 'business_date' => today()->toDateString()]));

    $turn = firstWith($statements, 'from number_sequences', 'key = ?');
    $source = firstWith($statements, 'from journal_entries', 'source_type = ?');
    $period = firstWith($statements, 'from accounting_periods');

    expect($turn)->not->toBeNull('the series "journal" was never asked for')
        ->and($source)->not->toBeNull()
        ->and($period)->not->toBeNull()
        ->and($turn)->toBeLessThan($source, 'the journal was asked for the document before the ledger\'s turn was taken')
        ->and($turn)->toBeLessThan($period, 'the periods were locked before the ledger\'s turn was taken')
        ->and($statements[$turn][1])->toBe([LedgerTurn::SERIES]);
});

it('refuses to take the ledger\'s turn outside a transaction', function () {
    // The suite's own transaction is open here, so the turn is granted; closing it shows the refusal.
    app(LedgerTurn::class)->take();

    DB::rollBack();
    try {
        expect(fn () => app(LedgerTurn::class)->take())->toThrow(LogicException::class, 'inside one');
    } finally {
        DB::beginTransaction();
    }
});

it('asks whether a supplier\'s document is booked already only inside the ledger\'s turn', function (string $how) {
    $vendor = Vendor::query()->create(['name' => 'Order Timber', 'payment_terms_days' => 15, 'is_active' => true]);
    $account = (int) Account::query()->where('code', '5010')->value('id');

    $statements = statementsOf(fn () => $how === 'a bill'
        ? app(SaveBill::class)->handle(
            ['vendor_id' => $vendor->id, 'number' => 'ORDER-1', 'bill_date' => today()->toDateString()],
            [['account_id' => $account, 'description' => 'Veneer', 'amount_minor' => 9_000]],
            $this->owner,
            BillStatus::Received,
        )
        : app(RecordExpense::class)->handle([
            'direction' => 'out', 'account_id' => $account, 'amount_minor' => 9_000, 'spent_on' => today()->toDateString(),
            'paid_from' => 'cash', 'vendor_id' => $vendor->id, 'reference' => 'ORDER-1', 'description' => 'Veneer',
        ], $this->owner));

    $supplier = firstWith($statements, 'select * from vendors where vendors.id = ?');
    $turn = firstWith($statements, 'from number_sequences', 'key = ?');
    $bills = firstWith($statements, 'from bills where vendor_id = ? and number = ?');
    $expenses = firstWith($statements, 'from expenses where direction = ? and vendor_id = ? and reference = ?');

    expect([$supplier, $turn, $bills, $expenses])->not->toContain(null)
        ->and($supplier)->toBeLessThan($turn, 'the ledger\'s turn was taken before the supplier\'s own row')
        ->and($turn)->toBeLessThan($bills, 'the bills were asked for the reference before the ledger\'s turn was taken')
        ->and($turn)->toBeLessThan($expenses, 'the expenses were asked for the reference before the ledger\'s turn was taken');
})->with(['a bill', 'a paid cost']);

it('takes every invoice a client\'s money may reach, lowest number first, before a payment is written', function (string $how) {
    $other = orderInvoice(Contact::factory()->create());
    $first = orderInvoice($this->client);
    $paid = orderInvoice($this->client, 10_000, InvoiceStatus::Paid);
    $draft = orderInvoice($this->client, 10_000, InvoiceStatus::Draft);
    $second = orderInvoice($this->client);
    $mine = orderInvoice($this->client);

    $statements = statementsOf(fn () => match ($how) {
        'recorded' => app(RecordPayment::class)->handle(['contact_id' => $this->client->id, 'invoice_id' => $mine->id, 'amount_minor' => 4_000, 'received_on' => today()->toDateString(), 'method' => 'cash'], $this->owner),
        'collected' => app(CollectPayment::class)->handle($mine, 4_000, PaymentMethod::Cash, $this->owner),
    });

    $written = firstWith($statements, 'insert into payments');

    expect($written)->not->toBeNull()
        // The open invoices of this client and no other row: not the paid one, not the draft, not another client's.
        ->and(invoicesTakenBefore($statements, $written))->toBe([$first->id, $second->id, $mine->id])
        ->and($mine->refresh()->paid_minor)->toBe(4_000)
        ->and($first->refresh()->paid_minor)->toBe(0)
        ->and($other->refresh()->paid_minor)->toBe(0);
})->with(['recorded', 'collected']);

it('takes the invoices of a verified payment one by one by their own numbers, lowest first', function () {
    $first = orderInvoice($this->client);
    $mine = orderInvoice($this->client);
    $payment = Payment::query()->create(['contact_id' => $this->client->id, 'invoice_id' => $mine->id, 'amount_minor' => 15_000, 'received_on' => today(), 'method' => 'cash', 'status' => PaymentStatus::PendingVerification, 'submitted_by' => 'staff']);

    $statements = statementsOf(fn () => app(VerifyPayment::class)->handle($payment, $this->owner));
    $allocated = firstWith($statements, 'insert into payment_allocations');

    expect(invoicesTakenBefore($statements, $allocated))->toBe([$first->id, $mine->id])
        // Its own invoice in full, the rest to the older one.
        ->and($mine->refresh()->paid_minor)->toBe(10_000)
        ->and($first->refresh()->paid_minor)->toBe(5_000);
});

it('leaves out of the locks what is not an invoice, and returns the rest as they are now', function () {
    $second = orderInvoice($this->client);
    $first = orderInvoice($this->client);
    $locks = app(InvoiceLocks::class);

    $held = $locks->take([$first->id, 999_999, $second->id, $first->id]);

    expect($held->keys()->all())->toBe([$second->id, $first->id])
        ->and($locks->openOf($this->client->id))->toBe([$second->id, $first->id])
        ->and($locks->forMoneyOf($this->client->id, ['x', null, -3, '7', 999_999])->keys()->all())->toBe([$second->id, $first->id]);

    $first->update(['status' => InvoiceStatus::Paid, 'paid_minor' => 10_000, 'balance_minor' => 0]);

    expect($locks->openOf($this->client->id))->toBe([$second->id])
        ->and($locks->forMoneyOf($this->client->id, [$first->id])->get($first->id)->status)->toBe(InvoiceStatus::Paid);
});

/*
| The inventory. In the three parts of the application that write the books, a lock names ONE row by its own key.
| A lock on "the rows like this" holds whatever the database reads on its way, and the empty places between them:
| that is what two writers were stopped by. Each lock that is not on one row by its key stands here with the reason
| it is safe. A new one fails this test until it is by key, or until it is added here by somebody who has read
| App\Domain\Accounts\Services\LedgerTurn and given tools/ci/two-writers.php a step that tries it.
*/
it('locks one row by its own key wherever the books are written, or says why not', function () {
    $known = [
        'Accounts/Actions/PostJournalEntry.php' => [
            "->where('source_id', \$sourceId) ->lockForUpdate() ->exists()" => 'inside the ledger\'s turn: is this document in the journal already',
            '->covering($businessDate)->lockForUpdate()->first()' => 'inside the ledger\'s turn: the period of the entry\'s date',
        ],
        'Accounts/Services/LedgerTurn.php' => [
            "->where('key', self::SERIES)->lockForUpdate()->first()" => 'the turn itself: one row, found by its name, which is a key',
        ],
        'Accounts/Services/SupplierCostReference.php' => [
            '->whereKeyNot($exceptBillId))->lockForUpdate()->exists()' => 'inside the ledger\'s turn: is this supplier\'s document a bill already',
            "->where('reference', \$reference)->lockForUpdate()->exists()" => 'inside the ledger\'s turn: is this supplier\'s document an expense already',
        ],
        'Projects/Actions/RecordProjectCost.php' => [
            "->where('request_key', \$requestKey)->lockForUpdate()->firstOrFail()" => 'one row, written just above by its key before it is locked',
        ],
        'Sales/Actions/AcceptQuote.php' => [
            '$quote->contact()->lockForUpdate()->firstOrFail()' => 'one row: the quotation\'s client',
        ],
        'Sales/Actions/RaiseMilestoneInvoice.php' => [
            '$quote->project()->lockForUpdate()->firstOrFail()' => 'one row: the quotation\'s project',
        ],
        'Sales/Actions/SaveDocumentDesign.php' => [
            "->where('business_line', \$line)->lockForUpdate()->firstOrFail()" => 'one row, found by a key; not the books',
        ],
        'Sales/Actions/CaptureStorefrontQuoteRequest.php' => [
            '->lockForUpdate()->first()' => 'a request from the shop asked for twice; not the books, and not tried with two writers',
        ],
    ];

    $unexplained = [];
    $met = [];
    $files = [...glob(app_path('Domain/{Accounts,Sales,Projects}/{Actions,Services,Models}/*.php'), GLOB_BRACE), app_path('Support/Numbering.php')];
    expect(count($files))->toBeGreaterThan(80);

    foreach ($files as $file) {
        $name = str_replace([app_path('Domain/'), app_path().'/'], '', $file);
        foreach (explode(';', (string) file_get_contents($file)) as $statement) {
            if (! str_contains($statement, 'lockForUpdate()')) {
                continue;
            }
            $statement = trim((string) preg_replace('/\s+/', ' ', $statement));
            // One row by its key: find(), findOrFail(), whereKey(), or the series of a number by its name.
            if (preg_match('/->lockForUpdate\(\)->find(OrFail)?\(|whereKey\(/', $statement) || ($name === 'Support/Numbering.php' && str_contains($statement, "->where('key', \$key)->lockForUpdate()"))) {
                continue;
            }
            $reasons = array_filter($known[$name] ?? [], fn (string $words): bool => str_contains($statement, $words), ARRAY_FILTER_USE_KEY);
            if ($reasons === []) {
                $unexplained[] = "{$name}: {$statement}";
            }
            foreach (array_keys($reasons) as $words) {
                $met["{$name} {$words}"] = true;
            }
        }
    }

    expect($unexplained)->toBe([], 'a lock that is not on one row by its key, in a part that writes the books');

    // An entry of the list that no longer matches anything is taken off it, so the list stays what is true.
    foreach ($known as $name => $entries) {
        foreach (array_keys($entries) as $words) {
            expect($met)->toHaveKey("{$name} {$words}");
        }
    }
});

it('lets nobody but the posting itself ask the journal for a document with a lock', function () {
    $asking = [];
    foreach ((new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS))) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        foreach (explode(';', (string) file_get_contents($file->getPathname())) as $statement) {
            if (str_contains($statement, 'JournalEntry::') && str_contains($statement, 'source_type') && str_contains($statement, 'lockForUpdate()')) {
                $asking[] = str_replace(app_path().'/', '', $file->getPathname());
            }
        }
    }

    expect(array_values(array_unique($asking)))->toBe(['Domain/Accounts/Actions/PostJournalEntry.php']);
});
