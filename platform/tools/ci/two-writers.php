<?php

declare(strict_types=1);

/**
 * Two people writing to the ledger at the same moment (master list T-093).
 *
 * The suite calls every action one after another and inside one transaction, and SQLite lets one writer in at a
 * time: neither can show what happens when two requests meet. This command can. It starts two PHP processes of
 * this application ("writers"), hands both the same record as two browsers would hold it, lets them wait at one
 * gate and then call the same action. It does that for every step below, fifty times a step; the second writer
 * starts at the same instant or a part of the way into the first one's work, so that the two meet at every stage.
 *
 * After every run it asks the two answers and the database:
 *   - where both work on ONE record: exactly one of them wrote. The other was refused in the action's own words,
 *     or waited and was shown what the first one wrote.
 *   - where each works on a record of its OWN: both wrote.
 *   - an answer from the database instead of the action (a deadlock, a wait that ran out, a key met twice) is a
 *     fault in every case: the person would be looking at an error page.
 *   - every journal entry balances, no invoice is paid beyond its total, no bill is paid twice, no number of a
 *     document is given twice or left out, and the receivable and payable accounts say what the documents say.
 *
 * Usage, from platform/, with the settings of a MySQL or MariaDB database that may be emptied:
 *   php tools/ci/two-writers.php [--runs=50] [--only=verify,reverse] [--json=<file>] [--list]
 *
 * The check of the check: the writers can be given a copy of one class with every lock taken out of it. The
 * application's own file is not touched. With --expect-fault the command succeeds only if a fault with these
 * words was then reported:
 *   php tools/ci/two-writers.php --only=two-payments-one-invoice \
 *       --without-locks=app/Domain/Sales/Actions/AllocatePayment.php --expect-fault='paid beyond its total'
 *
 * It EMPTIES the database it is given. It therefore refuses to start unless APP_ENV is "testing", the connection
 * is MySQL or MariaDB and the name of the database ends in "_test".
 */

use App\Domain\Accounts\Actions\PayBill;
use App\Domain\Accounts\Actions\PostInvoiceToLedger;
use App\Domain\Accounts\Actions\PostJournalEntry;
use App\Domain\Accounts\Actions\ReceiveBill;
use App\Domain\Accounts\Actions\RecordExpense;
use App\Domain\Accounts\Actions\ReverseJournalEntry;
use App\Domain\Accounts\Actions\SaveBill;
use App\Domain\Accounts\Enums\BillStatus;
use App\Domain\Accounts\Exceptions\JournalPostingFailed;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\Bill;
use App\Domain\Accounts\Models\JournalEntry;
use App\Domain\Accounts\Models\Vendor;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Sales\Actions\AllocatePayment;
use App\Domain\Sales\Actions\CollectPayment;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Actions\RecordPayment;
use App\Domain\Sales\Actions\RejectPayment;
use App\Domain\Sales\Actions\VerifyPayment;
use App\Domain\Sales\Actions\VoidInvoice;
use App\Domain\Sales\Enums\InvoiceKind;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Enums\PaymentStatus;
use App\Domain\Sales\Exceptions\QuoteActionDenied;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Domain\System\Models\NumberSequence;
use App\Models\User;
use App\Support\Numbering;
use Database\Seeders\ProductionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

const TW_MARK = '@@two-writers ';
const TW_SERIES = 'two.writers';
const TW_NUMBERS_EACH = 5;

/** How far into the first writer's work the second one starts, as a part of the time that work takes alone. */
const TW_PARTS = [0.0, 0.0, 0.03, 0.1, 0.25, 0.5, 0.75];

$base = dirname(__DIR__, 2);

$options = ['runs' => '50', 'only' => null, 'json' => null, 'without-locks' => null, 'expect-fault' => null, 'worker' => null, 'list' => false, 'help' => false];
foreach (array_slice($argv, 1) as $argument) {
    if (! preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $argument, $m) || ! array_key_exists($m[1], $options)) {
        fwrite(STDERR, "two-writers: unknown argument {$argument} (see the head of this file)\n");
        exit(2);
    }
    $options[$m[1]] = is_bool($options[$m[1]]) ? true : ($m[2] ?? '');
}

if ($options['help']) {
    preg_match('~/\*\*(.*?)\*/~s', (string) file_get_contents(__FILE__), $head);
    fwrite(STDOUT, trim((string) preg_replace('/^ \* ?/m', '', $head[1] ?? '')).PHP_EOL);
    exit(0);
}

if (! $options['list'] && getenv('APP_ENV') !== 'testing') {
    fwrite(STDERR, "two-writers: refused. APP_ENV must be \"testing\": this command empties the database it is given.\n");
    exit(2);
}

require $base.'/vendor/autoload.php';

$scratch = (string) (getenv('TWO_WRITERS_SCRATCH') ?: sys_get_temp_dir().'/two-writers-'.getmypid());
if (! is_dir($scratch.'/private') && ! mkdir($scratch.'/private', 0700, true) && ! is_dir($scratch.'/private')) {
    fwrite(STDERR, "two-writers: cannot create {$scratch}\n");
    exit(2);
}

// A writer may be given a copy of one class without its locks. The copy is declared before anything asks for the
// class, so the application's own file is never read by that writer; nothing in app/ is changed.
if ($options['worker'] !== null && $options['without-locks'] !== null) {
    $source = (string) file_get_contents($base.'/'.$options['without-locks']);
    $copy = $scratch.'/without-locks-'.$options['worker'].'-'.basename($options['without-locks']);
    file_put_contents($copy, str_replace('->lockForUpdate()', '', $source));
    require $copy;
}

$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Files the actions write (receipts, frozen invoices) go to a folder of this run; nothing may call another server.
config(['filesystems.disks.local.root' => $scratch.'/private', 'documents.test_bypass' => true]);
Http::preventStrayRequests();

/* ------------------------------------------------------------------------------------------------------------
 | The steps. "prepare" runs in this command and makes the records of one run; "hold" runs in each writer before
 | the gate and loads what a screen would be holding; "act" is what each writer does when the gate opens; "after"
 | asks the database what was written.
 * ---------------------------------------------------------------------------------------------------------- */

/**
 * @return array<string, array{
 *     title: string, writers: 'one'|'both', refusals?: list<string>, shown?: list<string>,
 *     prepare: Closure(TwoWritersWorld, int): array{a: array<string, mixed>, b: array<string, mixed>, facts: array<string, mixed>},
 *     hold: Closure(array<string, mixed>): array<string, mixed>,
 *     act: Closure(array<string, mixed>): array<string, mixed>,
 *     after: Closure(array<string, mixed>, array<string, mixed>, array<string, mixed>): list<string>,
 * }>
 */
function twoWritersSteps(): array
{
    $user = static fn (array $ids): User => User::query()->findOrFail($ids['by']);
    $entriesOf = static fn (string $type, int $id): int => DB::table('journal_entries')->where('source_type', $type)->where('source_id', $id)->count();
    $verify = static function (array $held): array {
        $payment = app(VerifyPayment::class)->handle($held['payment'], $held['by']);

        return ['payment' => $payment->id, 'status' => $payment->status->value, 'receipt' => $payment->receipt_number];
    };
    $holdPayment = static fn (array $ids): array => ['payment' => Payment::query()->findOrFail($ids['payment']), 'by' => $user($ids)];
    $holdInvoice = static fn (array $ids): array => ['invoice' => Invoice::query()->findOrFail($ids['invoice']), 'by' => $user($ids)] + $ids;
    $holdBill = static fn (array $ids): array => ['bill' => Bill::query()->findOrFail($ids['bill']), 'by' => $user($ids)] + $ids;
    $payBill = static function (array $held): array {
        $payment = app(PayBill::class)->handle($held['bill'], $held['amount'], PaymentMethod::BankTransfer, $held['by'], 'TWO-WRITERS', null, $held['key']);

        return ['bill_payment' => $payment->id];
    };
    $collect = static function (array $held): array {
        $payment = app(CollectPayment::class)->handle($held['invoice'], $held['amount'], PaymentMethod::Cash, $held['by'], 'TWO-WRITERS');

        return ['payment' => $payment->id, 'receipt' => $payment->receipt_number];
    };
    $issue = static function (array $held): array {
        $invoice = app(IssueInvoice::class)->handle($held['invoice'], $held['by']);

        return ['invoice' => $invoice->id, 'status' => $invoice->status->value];
    };
    $record = static function (array $held): array {
        $payment = app(RecordPayment::class)->handle([
            'contact_id' => $held['invoice']->contact_id, 'invoice_id' => $held['invoice']->id, 'amount_minor' => $held['amount'],
            'received_on' => today()->toDateString(), 'method' => 'cash', 'reference' => 'TWO-WRITERS',
        ], $held['by']);

        return ['payment' => $payment->id, 'receipt' => $payment->receipt_number];
    };
    $receive = static function (array $held): array {
        $bill = app(ReceiveBill::class)->handle($held['bill'], $held['by']);

        return ['bill' => $bill->id, 'status' => $bill->status->value];
    };
    $saveBill = static function (array $held): array {
        $bill = app(SaveBill::class)->handle(
            ['vendor_id' => $held['vendor'], 'number' => $held['number'], 'bill_date' => today()->toDateString(), 'due_date' => today()->addDays(15)->toDateString()],
            [['account_id' => $held['account'], 'description' => 'Two writers', 'amount_minor' => $held['amount']]],
            $held['by'],
            BillStatus::Received,
        );

        return ['bill' => $bill->id, 'status' => $bill->status->value];
    };
    $expense = static function (array $held): array {
        $expense = app(RecordExpense::class)->handle([
            'direction' => 'out', 'account_id' => $held['account'], 'amount_minor' => $held['amount'], 'spent_on' => today()->toDateString(),
            'paid_from' => 'cash', 'vendor_id' => $held['vendor'] ?? null, 'reference' => $held['reference'] ?? null, 'description' => $held['description'],
        ], $held['by']);

        return ['expense' => $expense->id];
    };
    $holdPerson = static fn (array $ids): array => ['by' => $user($ids)] + $ids;
    $expensesPosted = static function (array $descriptions) use ($entriesOf): array {
        $rows = DB::table('expenses')->whereIn('description', $descriptions)->get();
        $faults = $rows->count() === count($descriptions) ? [] : [$rows->count().' of the '.count($descriptions).' expenses were written'];
        foreach ($rows as $row) {
            if ($row->journal_entry_id === null || $entriesOf('expense', (int) $row->id) !== 1) {
                $faults[] = "expense {$row->id} is not in the journal once";
            }
        }

        return $faults;
    };
    $received = static function (int $id) use ($entriesOf): array {
        $row = DB::table('bills')->where('id', $id)->first();
        $entries = $entriesOf('bill', $id);

        return $row->status === 'received' && $row->journal_entry_id !== null && $entries === 1
            ? []
            : ["bill {$row->number} is {$row->status} with {$entries} journal entries after it was received; a received bill has one and names it"];
    };
    $paymentFacts = static function (int $id) use ($entriesOf): array {
        $row = DB::table('payments')->where('id', $id)->first();

        return [
            'status' => $row->status,
            'receipt' => $row->receipt_number,
            'allocations' => DB::table('payment_allocations')->where('payment_id', $id)->count(),
            'allocated' => (int) DB::table('payment_allocations')->where('payment_id', $id)->sum('amount_minor'),
            'entries' => $entriesOf('payment', $id),
        ];
    };
    $verified = static function (int $id, int $allocated) use ($paymentFacts): array {
        $is = $paymentFacts($id);
        $faults = [];
        if ($is['status'] !== 'verified' || $is['receipt'] === null) {
            $faults[] = "payment {$id} is {$is['status']} with ".($is['receipt'] === null ? 'no receipt' : "receipt {$is['receipt']}").' after it was verified';
        }
        if ($is['allocated'] !== $allocated) {
            $faults[] = "payment {$id} has {$is['allocations']} allocations of {$is['allocated']} paisa in all; {$allocated} paisa was to be allocated";
        }
        if ($is['entries'] !== 1) {
            $faults[] = "payment {$id} has {$is['entries']} journal entries; a verified payment has one";
        }

        return $faults;
    };
    $untouched = static function (int $id) use ($paymentFacts): array {
        $is = $paymentFacts($id);

        return $is['status'] === 'pending_verification' && $is['receipt'] === null && $is['allocations'] === 0 && $is['entries'] === 0
            ? []
            : ["payment {$id}, which was refused, is {$is['status']} with {$is['allocations']} allocations, {$is['entries']} journal entries and ".($is['receipt'] === null ? 'no receipt' : "receipt {$is['receipt']}")];
    };
    $invoicePaid = static function (int $id, int $paid): array {
        $row = DB::table('invoices')->where('id', $id)->first();
        $allocated = (int) DB::table('payment_allocations')->where('invoice_id', $id)->sum('amount_minor');
        $faults = [];
        if ((int) $row->paid_minor !== $paid) {
            $faults[] = "invoice {$row->number} says {$row->paid_minor} paisa paid; {$paid} paisa was paid";
        }
        if ($allocated > (int) $row->total_minor) {
            $faults[] = "invoice {$row->number} is paid beyond its total: {$allocated} paisa allocated to an invoice of {$row->total_minor} paisa";
        }

        return $faults;
    };
    $issued = static function (int $id) use ($entriesOf): array {
        $row = DB::table('invoices')->where('id', $id)->first();
        $faults = [];
        if ($row->status !== 'issued' || $row->pdf_path === null) {
            $faults[] = "invoice {$row->number} is {$row->status} ".($row->pdf_path === null ? 'without' : 'with').' its frozen document after it was issued';
        }
        if (($entries = $entriesOf('invoice', $id)) !== 1) {
            $faults[] = "invoice {$row->number} has {$entries} journal entries; an issued invoice has one";
        }

        return $faults;
    };
    $billPaid = static function (int $id, int $payments) use ($entriesOf): array {
        $row = DB::table('bills')->where('id', $id)->first();
        $rows = DB::table('bill_payments')->where('bill_id', $id)->get();
        $faults = [];
        if ($rows->count() !== $payments) {
            $faults[] = "bill {$row->number} has {$rows->count()} payments of {$rows->sum('amount_minor')} paisa in all against a total of {$row->total_minor} paisa: ".($rows->count() > $payments ? 'the bill is paid twice' : 'a payment is missing');
        }
        if ((int) $row->paid_minor !== (int) $row->total_minor || $row->status !== 'paid') {
            $faults[] = "bill {$row->number} is {$row->status} with {$row->paid_minor} of {$row->total_minor} paisa paid after it was paid in full";
        }
        foreach ($rows as $payment) {
            if (($entries = $entriesOf('bill_payment', (int) $payment->id)) !== 1) {
                $faults[] = "the payment of bill {$row->number} has {$entries} journal entries; it has one";
            }
        }

        return $faults;
    };

    return [
        'allocate' => [
            'title' => 'one payment allocated to one invoice',
            'writers' => 'one',
            'shown' => ['allocated'],
            // The invoice it was recorded for is paid by then, and the action says so in the words it has.
            'refusals' => ['/Choose a payable PKR invoice for this client/'],
            'prepare' => function (TwoWritersWorld $world, int $run): array {
                $invoice = $world->invoice($world->client(), $amount = 400_000 + $run);
                $payment = $world->payment($invoice, $amount, PaymentStatus::Verified);

                return ['a' => ['payment' => $payment->id], 'b' => ['payment' => $payment->id], 'facts' => ['payment' => $payment->id, 'invoice' => $invoice->id, 'amount' => $amount]];
            },
            'hold' => static fn (array $ids): array => ['payment' => Payment::query()->findOrFail($ids['payment'])],
            'act' => static function (array $held): array {
                $payment = app(AllocatePayment::class)->handle($held['payment']);

                return ['allocated' => (int) $payment->allocations()->sum('amount_minor')];
            },
            'after' => function (array $facts) use ($paymentFacts, $invoicePaid): array {
                $is = $paymentFacts($facts['payment']);

                return [
                    ...($is['allocations'] === 1 && $is['allocated'] === $facts['amount'] ? [] : ["payment {$facts['payment']} of {$facts['amount']} paisa has {$is['allocations']} allocations of {$is['allocated']} paisa in all"]),
                    ...$invoicePaid($facts['invoice'], $facts['amount']),
                ];
            },
        ],
        'allocate-chosen' => [
            'title' => 'one payment allocated to an invoice chosen by hand',
            'writers' => 'one',
            'refusals' => ['/within the unallocated payment/', '/exceeds the invoice balance or duplicates an existing allocation/'],
            'prepare' => function (TwoWritersWorld $world, int $run): array {
                $invoice = $world->invoice($world->client(), $amount = 410_000 + $run);
                $payment = $world->payment($invoice, $amount, PaymentStatus::Verified, false);
                $ids = ['payment' => $payment->id, 'invoice' => $invoice->id, 'amount' => $amount];

                return ['a' => $ids, 'b' => $ids, 'facts' => $ids];
            },
            'hold' => static fn (array $ids): array => ['payment' => Payment::query()->findOrFail($ids['payment'])] + $ids,
            'act' => static function (array $held): array {
                $payment = app(AllocatePayment::class)->handle($held['payment'], [$held['invoice'] => $held['amount']]);

                return ['allocated' => (int) $payment->allocations()->sum('amount_minor')];
            },
            'after' => function (array $facts) use ($paymentFacts, $invoicePaid): array {
                $is = $paymentFacts($facts['payment']);

                return [
                    ...($is['allocations'] === 1 && $is['allocated'] === $facts['amount'] ? [] : ["payment {$facts['payment']} of {$facts['amount']} paisa has {$is['allocations']} allocations of {$is['allocated']} paisa in all"]),
                    ...$invoicePaid($facts['invoice'], $facts['amount']),
                ];
            },
        ],
        'verify' => [
            'title' => 'one payment verified',
            'writers' => 'one',
            'shown' => ['payment', 'status', 'receipt'],
            'prepare' => function (TwoWritersWorld $world, int $run): array {
                $invoice = $world->invoice($world->client(), $amount = 420_000 + $run);
                $payment = $world->payment($invoice, $amount, PaymentStatus::PendingVerification);

                return [
                    'a' => ['payment' => $payment->id, 'by' => $world->first->id],
                    'b' => ['payment' => $payment->id, 'by' => $world->second->id],
                    'facts' => ['payment' => $payment->id, 'invoice' => $invoice->id, 'amount' => $amount],
                ];
            },
            'hold' => $holdPayment,
            'act' => $verify,
            'after' => fn (array $facts): array => [...$verified($facts['payment'], $facts['amount']), ...$invoicePaid($facts['invoice'], $facts['amount'])],
        ],
        'two-payments-one-invoice' => [
            'title' => 'two payments, each for the whole of one invoice',
            'writers' => 'one',
            'refusals' => ['/Choose a payable PKR invoice for this client/'],
            'prepare' => function (TwoWritersWorld $world, int $run): array {
                $invoice = $world->invoice($world->client(), $amount = 430_000 + $run);
                $one = $world->payment($invoice, $amount, PaymentStatus::PendingVerification);
                $two = $world->payment($invoice, $amount, PaymentStatus::PendingVerification);

                return [
                    'a' => ['payment' => $one->id, 'by' => $world->first->id],
                    'b' => ['payment' => $two->id, 'by' => $world->second->id],
                    'facts' => ['payments' => [$one->id, $two->id], 'invoice' => $invoice->id, 'amount' => $amount],
                ];
            },
            'hold' => $holdPayment,
            'act' => $verify,
            'after' => function (array $facts) use ($paymentFacts, $verified, $untouched, $invoicePaid): array {
                $faults = $invoicePaid($facts['invoice'], $facts['amount']);
                $done = array_values(array_filter($facts['payments'], fn (int $id): bool => $paymentFacts($id)['status'] === 'verified'));
                if (count($done) !== 1) {
                    $faults[] = count($done).' of the two payments are verified against an invoice that one of them pays in full';
                }
                foreach ($facts['payments'] as $id) {
                    $faults = [...$faults, ...(in_array($id, $done, true) ? $verified($id, $facts['amount']) : $untouched($id))];
                }

                return $faults;
            },
        ],
        'two-part-payments' => [
            'title' => 'two payments of six tenths each for one invoice',
            'writers' => 'both',
            'prepare' => function (TwoWritersWorld $world, int $run): array {
                $invoice = $world->invoice($world->client(), $total = 500_000 + 10 * $run);
                $part = intdiv($total * 6, 10);
                $one = $world->payment($invoice, $part, PaymentStatus::PendingVerification);
                $two = $world->payment($invoice, $part, PaymentStatus::PendingVerification);

                return [
                    'a' => ['payment' => $one->id, 'by' => $world->first->id],
                    'b' => ['payment' => $two->id, 'by' => $world->second->id],
                    'facts' => ['payments' => [$one->id, $two->id], 'invoice' => $invoice->id, 'total' => $total, 'part' => $part],
                ];
            },
            'hold' => $holdPayment,
            'act' => $verify,
            'after' => function (array $facts) use ($paymentFacts, $invoicePaid): array {
                $faults = $invoicePaid($facts['invoice'], $facts['total']);
                $allocated = array_map(fn (int $id): int => $paymentFacts($id)['allocated'], $facts['payments']);
                sort($allocated);
                if ($allocated !== [$facts['total'] - $facts['part'], $facts['part']]) {
                    $faults[] = 'the two payments of '.$facts['part'].' paisa were allocated '.implode(' and ', $allocated).' paisa to an invoice of '.$facts['total'].' paisa; one settles its whole amount and the other what is left';
                }

                return $faults;
            },
        ],
        'collect' => [
            'title' => 'the whole of one invoice collected',
            'writers' => 'one',
            'refusals' => ['/and cannot collect a payment/', '/has only .* outstanding/'],
            'prepare' => function (TwoWritersWorld $world, int $run): array {
                $invoice = $world->invoice($world->client(), $amount = 440_000 + $run);

                return [
                    'a' => ['invoice' => $invoice->id, 'amount' => $amount, 'by' => $world->first->id],
                    'b' => ['invoice' => $invoice->id, 'amount' => $amount, 'by' => $world->second->id],
                    'facts' => ['invoice' => $invoice->id, 'amount' => $amount],
                ];
            },
            'hold' => $holdInvoice,
            'act' => $collect,
            'after' => function (array $facts) use ($verified, $invoicePaid): array {
                $payments = DB::table('payments')->where('invoice_id', $facts['invoice'])->pluck('id')->all();
                $faults = count($payments) === 1 ? $verified((int) $payments[0], $facts['amount']) : [count($payments).' payments were recorded for one collection of the whole invoice'];

                return [...$faults, ...$invoicePaid($facts['invoice'], $facts['amount'])];
            },
        ],
        'record-payment' => [
            'title' => 'two payments recorded, each for the whole of one invoice',
            'writers' => 'one',
            'refusals' => ['/Choose a payable PKR invoice for this client/'],
            'prepare' => function (TwoWritersWorld $world, int $run): array {
                $invoice = $world->invoice($world->client(), $amount = 445_000 + $run);

                return [
                    'a' => ['invoice' => $invoice->id, 'amount' => $amount, 'by' => $world->first->id],
                    'b' => ['invoice' => $invoice->id, 'amount' => $amount, 'by' => $world->second->id],
                    'facts' => ['invoice' => $invoice->id, 'amount' => $amount],
                ];
            },
            'hold' => $holdInvoice,
            'act' => $record,
            'after' => function (array $facts) use ($verified, $invoicePaid): array {
                $payments = DB::table('payments')->where('invoice_id', $facts['invoice'])->pluck('id')->all();
                $faults = count($payments) === 1 ? $verified((int) $payments[0], $facts['amount']) : [count($payments).' payments stand recorded for an invoice that one of them pays in full'];

                return [...$faults, ...$invoicePaid($facts['invoice'], $facts['amount'])];
            },
        ],
        'void-or-pay' => [
            'title' => 'one invoice voided while its payment is verified',
            'writers' => 'one',
            'refusals' => ['/has payments against it/', '/Choose a payable PKR invoice for this client/'],
            'prepare' => function (TwoWritersWorld $world, int $run): array {
                $invoice = $world->invoice($world->client(), $amount = 447_000 + $run);
                $payment = $world->payment($invoice, $amount, PaymentStatus::PendingVerification);

                return [
                    'a' => ['do' => 'void', 'invoice' => $invoice->id, 'by' => $world->first->id],
                    'b' => ['do' => 'verify', 'payment' => $payment->id, 'by' => $world->second->id],
                    'facts' => ['invoice' => $invoice->id, 'payment' => $payment->id, 'amount' => $amount],
                ];
            },
            'hold' => static fn (array $ids): array => $ids['do'] === 'void'
                ? ['do' => 'void', 'invoice' => Invoice::query()->findOrFail($ids['invoice']), 'by' => $user($ids)]
                : ['do' => 'verify', 'payment' => Payment::query()->findOrFail($ids['payment']), 'by' => $user($ids)],
            'act' => static function (array $held) use ($verify): array {
                if ($held['do'] === 'verify') {
                    return $verify($held);
                }
                $invoice = app(VoidInvoice::class)->handle($held['invoice'], $held['by'], 'Two writers at once.');

                return ['invoice' => $invoice->id, 'status' => $invoice->status->value];
            },
            'after' => function (array $facts) use ($verified, $untouched, $invoicePaid): array {
                $row = DB::table('invoices')->where('id', $facts['invoice'])->first();
                if ($row->status === 'void') {
                    $entry = DB::table('journal_entries')->where('source_type', 'invoice')->where('source_id', $facts['invoice'])->first();

                    return [...$untouched($facts['payment']), ...$invoicePaid($facts['invoice'], 0), ...($entry->reversed_by_entry_id === null ? ["invoice {$row->number} is void and its journal entry is not reversed"] : [])];
                }

                return [...$verified($facts['payment'], $facts['amount']), ...$invoicePaid($facts['invoice'], $facts['amount']), ...($row->status === 'paid' ? [] : ["invoice {$row->number} is {$row->status} after its payment was verified"])];
            },
        ],
        'verify-or-reject' => [
            'title' => 'one payment verified while it is sent back',
            'writers' => 'one',
            'shown' => ['payment', 'status', 'receipt'],
            'prepare' => function (TwoWritersWorld $world, int $run): array {
                $invoice = $world->invoice($world->client(), $amount = 449_000 + $run);
                $payment = $world->payment($invoice, $amount, PaymentStatus::PendingVerification);

                return [
                    'a' => ['do' => 'verify', 'payment' => $payment->id, 'by' => $world->first->id],
                    'b' => ['do' => 'reject', 'payment' => $payment->id, 'by' => $world->second->id],
                    'facts' => ['invoice' => $invoice->id, 'payment' => $payment->id, 'amount' => $amount],
                ];
            },
            'hold' => static fn (array $ids): array => ['do' => $ids['do'], 'payment' => Payment::query()->findOrFail($ids['payment']), 'by' => $user($ids)],
            'act' => static function (array $held) use ($verify): array {
                if ($held['do'] === 'verify') {
                    return $verify($held);
                }
                $payment = app(RejectPayment::class)->handle($held['payment'], 'Two writers at once.', $held['by']);

                return ['payment' => $payment->id, 'status' => $payment->status->value, 'receipt' => $payment->receipt_number];
            },
            'after' => function (array $facts) use ($paymentFacts, $verified, $invoicePaid): array {
                $is = $paymentFacts($facts['payment']);
                if ($is['status'] === 'verified') {
                    return [...$verified($facts['payment'], $facts['amount']), ...$invoicePaid($facts['invoice'], $facts['amount'])];
                }

                return [
                    ...($is['status'] === 'rejected' && $is['receipt'] === null && $is['allocations'] === 0 && $is['entries'] === 0 ? [] : ["payment {$facts['payment']} is {$is['status']} with {$is['allocations']} allocations and {$is['entries']} journal entries after it was sent back"]),
                    ...$invoicePaid($facts['invoice'], 0),
                ];
            },
        ],
        'pay-bill' => [
            'title' => 'one supplier\'s bill paid in full',
            'writers' => 'one',
            'refusals' => ['/and cannot take a payment/', '/has only .* left/'],
            'prepare' => function (TwoWritersWorld $world, int $run): array {
                $bill = $world->bill($amount = 450_000 + $run);

                return [
                    'a' => ['bill' => $bill->id, 'amount' => $amount, 'key' => (string) Str::uuid(), 'by' => $world->first->id],
                    'b' => ['bill' => $bill->id, 'amount' => $amount, 'key' => (string) Str::uuid(), 'by' => $world->second->id],
                    'facts' => ['bill' => $bill->id],
                ];
            },
            'hold' => $holdBill,
            'act' => $payBill,
            'after' => fn (array $facts): array => $billPaid($facts['bill'], 1),
        ],
        'pay-bill-sent-twice' => [
            'title' => 'one payment of a bill sent twice',
            'writers' => 'one',
            'shown' => ['bill_payment'],
            'prepare' => function (TwoWritersWorld $world, int $run): array {
                $bill = $world->bill($amount = 460_000 + $run);
                $ids = ['bill' => $bill->id, 'amount' => $amount, 'key' => (string) Str::uuid(), 'by' => $world->first->id];

                return ['a' => $ids, 'b' => $ids, 'facts' => ['bill' => $bill->id]];
            },
            'hold' => $holdBill,
            'act' => $payBill,
            'after' => fn (array $facts): array => $billPaid($facts['bill'], 1),
        ],
        'one-key-two-bills' => [
            'title' => 'one payment request sent for two bills',
            'writers' => 'one',
            'refusals' => ['/already used with different details/'],
            'prepare' => function (TwoWritersWorld $world, int $run): array {
                $one = $world->bill($first = 462_000 + $run);
                $two = $world->bill($second = 464_000 + $run);
                $key = (string) Str::uuid();

                return [
                    'a' => ['bill' => $one->id, 'amount' => $first, 'key' => $key, 'by' => $world->first->id],
                    'b' => ['bill' => $two->id, 'amount' => $second, 'key' => $key, 'by' => $world->second->id],
                    'facts' => ['bills' => [$one->id, $two->id]],
                ];
            },
            'hold' => $holdBill,
            'act' => $payBill,
            'after' => function (array $facts): array {
                $paid = DB::table('bill_payments')->whereIn('bill_id', $facts['bills'])->count();

                return $paid === 1 ? [] : ["{$paid} payments were written for one request"];
            },
        ],
        'receive-bill' => [
            'title' => 'one supplier\'s bill received into the ledger',
            'writers' => 'one',
            'refusals' => ['/already posted or void/'],
            'prepare' => function (TwoWritersWorld $world, int $run): array {
                $bill = $world->bill(466_000 + $run, false);

                return [
                    'a' => ['bill' => $bill->id, 'by' => $world->first->id],
                    'b' => ['bill' => $bill->id, 'by' => $world->second->id],
                    'facts' => ['bill' => $bill->id],
                ];
            },
            'hold' => $holdBill,
            'act' => $receive,
            'after' => fn (array $facts): array => $received($facts['bill']),
        ],
        'post-invoice' => [
            'title' => 'one invoice posted to the ledger',
            'writers' => 'one',
            'shown' => ['entry'],
            'refusals' => ['/has already been posted/'],
            'prepare' => function (TwoWritersWorld $world, int $run): array {
                $invoice = $world->invoice($world->client(), 470_000 + $run, InvoiceStatus::Issued, false);

                return [
                    'a' => ['invoice' => $invoice->id, 'by' => $world->first->id],
                    'b' => ['invoice' => $invoice->id, 'by' => $world->second->id],
                    'facts' => ['invoice' => $invoice->id],
                ];
            },
            'hold' => $holdInvoice,
            'act' => static function (array $held): array {
                $entry = app(PostInvoiceToLedger::class)->handle($held['invoice'], $held['by']);

                return ['entry' => $entry?->id];
            },
            'after' => function (array $facts) use ($entriesOf): array {
                $entries = $entriesOf('invoice', $facts['invoice']);

                return $entries === 1 ? [] : ["the invoice has {$entries} journal entries; it has one"];
            },
        ],
        'issue-invoice' => [
            'title' => 'one draft invoice issued',
            'writers' => 'one',
            'refusals' => ['/Only a draft invoice can be issued/'],
            'prepare' => function (TwoWritersWorld $world, int $run): array {
                $invoice = $world->invoice($world->client(), 480_000 + $run, InvoiceStatus::Draft, false);

                return [
                    'a' => ['invoice' => $invoice->id, 'by' => $world->first->id],
                    'b' => ['invoice' => $invoice->id, 'by' => $world->second->id],
                    'facts' => ['invoice' => $invoice->id],
                ];
            },
            'hold' => $holdInvoice,
            'act' => $issue,
            'after' => fn (array $facts): array => $issued($facts['invoice']),
        ],
        'reverse' => [
            'title' => 'one journal entry reversed',
            'writers' => 'one',
            'refusals' => ['/is already reversed by/'],
            'prepare' => function (TwoWritersWorld $world, int $run): array {
                $entry = $world->entry($world->first, 490_000 + $run, "TWO-WRITERS reverse {$run}");

                return [
                    'a' => ['entry' => $entry->id, 'by' => $world->first->id],
                    'b' => ['entry' => $entry->id, 'by' => $world->second->id],
                    'facts' => ['entry' => $entry->id],
                ];
            },
            'hold' => static fn (array $ids): array => ['entry' => JournalEntry::query()->findOrFail($ids['entry']), 'by' => $user($ids)],
            'act' => static function (array $held): array {
                $reversal = app(ReverseJournalEntry::class)->handle($held['entry'], $held['by'], 'Two writers at once.');

                return ['entry' => $reversal->id, 'entry_no' => $reversal->entry_no];
            },
            'after' => function (array $facts): array {
                $reversals = DB::table('journal_entries')->where('reverses_entry_id', $facts['entry'])->pluck('id')->all();
                $says = DB::table('journal_entries')->where('id', $facts['entry'])->value('reversed_by_entry_id');
                if (count($reversals) !== 1) {
                    return ['the entry has '.count($reversals).' reversals; an entry is reversed once'];
                }

                return (int) $says === (int) $reversals[0] ? [] : ['the entry does not name its reversal'];
            },
        ],
        'number' => [
            'title' => 'the next numbers of one series',
            'writers' => 'both',
            'prepare' => fn (TwoWritersWorld $world, int $run): array => ['a' => [], 'b' => [], 'facts' => ['next' => $world->nextOfSeries()]],
            'hold' => static fn (array $ids): array => [],
            'act' => static function (array $held): array {
                $numbers = [];
                for ($i = 0; $i < TW_NUMBERS_EACH; $i++) {
                    $numbers[] = app(Numbering::class)->next(TW_SERIES);
                }

                return ['numbers' => $numbers];
            },
            'after' => function (array $facts, array $a, array $b): array {
                $given = [...($a['value']['numbers'] ?? []), ...($b['value']['numbers'] ?? [])];
                $expected = array_map(fn (int $n): string => 'TW-'.$n, range($facts['next'], $facts['next'] + 2 * TW_NUMBERS_EACH - 1));
                $twice = array_keys(array_filter(array_count_values($given), fn (int $times): bool => $times > 1));
                sort($given, SORT_NATURAL);
                if ($twice !== []) {
                    return ['a number was given twice: '.implode(', ', $twice)];
                }

                return $given === $expected ? [] : ['the numbers given are not the next '.count($expected).' of the series: '.implode(', ', $given)];
            },
        ],
        'post-journal' => [
            'title' => 'two journal entries posted, one each',
            'writers' => 'both',
            'prepare' => function (TwoWritersWorld $world, int $run): array {
                $lines = fn (int $amount): array => [
                    ['account_id' => $world->account('1010'), 'debit_minor' => $amount, 'memo' => 'Two writers'],
                    ['account_id' => $world->account('3010'), 'credit_minor' => $amount, 'memo' => 'Two writers'],
                ];

                return [
                    'a' => ['lines' => $lines(1_000 + $run), 'reference' => "TWO-WRITERS journal {$run} a", 'by' => $world->first->id],
                    'b' => ['lines' => $lines(2_000 + $run), 'reference' => "TWO-WRITERS journal {$run} b", 'by' => $world->second->id],
                    'facts' => ['references' => ["TWO-WRITERS journal {$run} a", "TWO-WRITERS journal {$run} b"]],
                ];
            },
            'hold' => static fn (array $ids): array => ['by' => $user($ids)] + $ids,
            'act' => static function (array $held): array {
                $entry = app(PostJournalEntry::class)->handle($held['by'], $held['lines'], ['business_date' => today()->toDateString(), 'reference' => $held['reference'], 'note' => 'Two writers at once.']);

                return ['entry' => $entry->id, 'entry_no' => $entry->entry_no];
            },
            'after' => function (array $facts): array {
                $posted = DB::table('journal_entries')->whereIn('reference', $facts['references'])->count();

                return $posted === 2 ? [] : ["{$posted} of the two entries are in the journal"];
            },
        ],
        'two-invoices' => [
            'title' => 'two draft invoices issued, one each',
            'writers' => 'both',
            'prepare' => function (TwoWritersWorld $world, int $run): array {
                $one = $world->invoice($world->client(), 510_000 + $run, InvoiceStatus::Draft, false);
                $two = $world->invoice($world->client(), 520_000 + $run, InvoiceStatus::Draft, false);

                return [
                    'a' => ['invoice' => $one->id, 'by' => $world->first->id],
                    'b' => ['invoice' => $two->id, 'by' => $world->second->id],
                    'facts' => ['invoices' => [$one->id, $two->id]],
                ];
            },
            'hold' => $holdInvoice,
            'act' => $issue,
            'after' => fn (array $facts): array => [...$issued($facts['invoices'][0]), ...$issued($facts['invoices'][1])],
        ],
        'two-verifications' => [
            'title' => 'two payments of two clients verified, one each',
            'writers' => 'both',
            'prepare' => function (TwoWritersWorld $world, int $run): array {
                $made = [];
                foreach ([530_000 + $run, 540_000 + $run] as $amount) {
                    $invoice = $world->invoice($world->client(), $amount);
                    $made[] = ['payment' => $world->payment($invoice, $amount, PaymentStatus::PendingVerification)->id, 'invoice' => $invoice->id, 'amount' => $amount];
                }

                return [
                    'a' => ['payment' => $made[0]['payment'], 'by' => $world->first->id],
                    'b' => ['payment' => $made[1]['payment'], 'by' => $world->second->id],
                    'facts' => ['made' => $made],
                ];
            },
            'hold' => $holdPayment,
            'act' => $verify,
            'after' => function (array $facts) use ($verified, $invoicePaid): array {
                $faults = [];
                foreach ($facts['made'] as $one) {
                    $faults = [...$faults, ...$verified($one['payment'], $one['amount']), ...$invoicePaid($one['invoice'], $one['amount'])];
                }

                return $faults;
            },
        ],
        'two-recordings-one-client' => [
            'title' => 'two payments recorded for two invoices of one client, one each',
            'writers' => 'both',
            'prepare' => function (TwoWritersWorld $world, int $run): array {
                $client = $world->client();
                $one = $world->invoice($client, $first = 542_000 + $run);
                $two = $world->invoice($client, $second = 544_000 + $run);

                return [
                    'a' => ['invoice' => $one->id, 'amount' => $first, 'by' => $world->first->id],
                    'b' => ['invoice' => $two->id, 'amount' => $second, 'by' => $world->second->id],
                    'facts' => ['invoices' => [[$one->id, $first], [$two->id, $second]]],
                ];
            },
            'hold' => $holdInvoice,
            'act' => $record,
            'after' => function (array $facts) use ($invoicePaid): array {
                $faults = [];
                foreach ($facts['invoices'] as [$id, $amount]) {
                    $faults = [...$faults, ...$invoicePaid($id, $amount)];
                }

                return $faults;
            },
        ],
        'two-collections' => [
            'title' => 'two invoices of two clients collected, one each',
            'writers' => 'both',
            'prepare' => function (TwoWritersWorld $world, int $run): array {
                $one = $world->invoice($world->client(), $first = 546_000 + $run);
                $two = $world->invoice($world->client(), $second = 548_000 + $run);

                return [
                    'a' => ['invoice' => $one->id, 'amount' => $first, 'by' => $world->first->id],
                    'b' => ['invoice' => $two->id, 'amount' => $second, 'by' => $world->second->id],
                    'facts' => ['invoices' => [[$one->id, $first], [$two->id, $second]]],
                ];
            },
            'hold' => $holdInvoice,
            'act' => $collect,
            'after' => function (array $facts) use ($invoicePaid): array {
                $faults = [];
                foreach ($facts['invoices'] as [$id, $amount]) {
                    $faults = [...$faults, ...$invoicePaid($id, $amount)];
                }

                return $faults;
            },
        ],
        'two-collections-one-client' => [
            'title' => 'two invoices of one client collected, one each',
            'writers' => 'both',
            'prepare' => function (TwoWritersWorld $world, int $run): array {
                $client = $world->client();
                $one = $world->invoice($client, $first = 550_000 + $run);
                $two = $world->invoice($client, $second = 560_000 + $run);

                return [
                    'a' => ['invoice' => $one->id, 'amount' => $first, 'by' => $world->first->id],
                    'b' => ['invoice' => $two->id, 'amount' => $second, 'by' => $world->second->id],
                    'facts' => ['invoices' => [[$one->id, $first], [$two->id, $second]]],
                ];
            },
            'hold' => $holdInvoice,
            'act' => $collect,
            'after' => function (array $facts) use ($invoicePaid): array {
                $faults = [];
                foreach ($facts['invoices'] as [$id, $amount]) {
                    $faults = [...$faults, ...$invoicePaid($id, $amount)];
                }

                return $faults;
            },
        ],
        'two-bills' => [
            'title' => 'two suppliers\' bills paid, one each',
            'writers' => 'both',
            'prepare' => function (TwoWritersWorld $world, int $run): array {
                $one = $world->bill($first = 570_000 + $run);
                $two = $world->bill($second = 580_000 + $run);

                return [
                    'a' => ['bill' => $one->id, 'amount' => $first, 'key' => (string) Str::uuid(), 'by' => $world->first->id],
                    'b' => ['bill' => $two->id, 'amount' => $second, 'key' => (string) Str::uuid(), 'by' => $world->second->id],
                    'facts' => ['bills' => [$one->id, $two->id]],
                ];
            },
            'hold' => $holdBill,
            'act' => $payBill,
            'after' => fn (array $facts): array => [...$billPaid($facts['bills'][0], 1), ...$billPaid($facts['bills'][1], 1)],
        ],
        'save-bill-twice' => [
            'title' => 'one new bill of a supplier saved twice',
            'writers' => 'one',
            'refusals' => ['/already recorded as a bill or expense/'],
            'prepare' => function (TwoWritersWorld $world, int $run): array {
                $ids = ['vendor' => $world->vendor()->id, 'number' => 'TW-SAVED-'.($run + 10), 'account' => $world->account('5010'), 'amount' => 600_000 + $run];

                return ['a' => $ids + ['by' => $world->first->id], 'b' => $ids + ['by' => $world->second->id], 'facts' => $ids];
            },
            'hold' => $holdPerson,
            'act' => $saveBill,
            'after' => function (array $facts) use ($received): array {
                $bills = DB::table('bills')->where('vendor_id', $facts['vendor'])->pluck('id')->all();

                return count($bills) === 1 ? $received((int) $bills[0]) : [count($bills).' bills stand saved for one supplier\'s document'];
            },
        ],
        'two-bills-saved' => [
            'title' => 'two new bills of two suppliers saved, one each',
            'writers' => 'both',
            'prepare' => function (TwoWritersWorld $world, int $run): array {
                $one = ['vendor' => $world->vendor()->id, 'number' => 'TW-A-'.($run + 10), 'account' => $world->account('5010'), 'amount' => 605_000 + $run];
                $two = ['vendor' => $world->vendor()->id, 'number' => 'TW-B-'.($run + 10), 'account' => $world->account('5010'), 'amount' => 610_000 + $run];

                return ['a' => $one + ['by' => $world->first->id], 'b' => $two + ['by' => $world->second->id], 'facts' => ['vendors' => [$one['vendor'], $two['vendor']]]];
            },
            'hold' => $holdPerson,
            'act' => $saveBill,
            'after' => function (array $facts) use ($received): array {
                $faults = [];
                foreach ($facts['vendors'] as $vendor) {
                    $bills = DB::table('bills')->where('vendor_id', $vendor)->pluck('id')->all();
                    $faults = [...$faults, ...(count($bills) === 1 ? $received((int) $bills[0]) : [count($bills).' bills stand saved for a supplier who sent one'])];
                }

                return $faults;
            },
        ],
        'two-expenses' => [
            'title' => 'two expenses recorded, one each',
            'writers' => 'both',
            'prepare' => function (TwoWritersWorld $world, int $run): array {
                $one = ['account' => $world->account('5190'), 'amount' => 615_000 + $run, 'description' => "TWO-WRITERS expense {$run} a"];
                $two = ['account' => $world->account('5190'), 'amount' => 620_000 + $run, 'description' => "TWO-WRITERS expense {$run} b"];

                return ['a' => $one + ['by' => $world->first->id], 'b' => $two + ['by' => $world->second->id], 'facts' => ['descriptions' => [$one['description'], $two['description']]]];
            },
            'hold' => $holdPerson,
            'act' => $expense,
            'after' => fn (array $facts): array => $expensesPosted($facts['descriptions']),
        ],
        'two-supplier-costs' => [
            'title' => 'two paid costs of two suppliers recorded, one each',
            'writers' => 'both',
            'prepare' => function (TwoWritersWorld $world, int $run): array {
                $one = ['vendor' => $world->vendor()->id, 'reference' => 'TW-COST-A-'.($run + 10), 'account' => $world->account('5010'), 'amount' => 625_000 + $run, 'description' => "TWO-WRITERS cost {$run} a"];
                $two = ['vendor' => $world->vendor()->id, 'reference' => 'TW-COST-B-'.($run + 10), 'account' => $world->account('5010'), 'amount' => 630_000 + $run, 'description' => "TWO-WRITERS cost {$run} b"];

                return ['a' => $one + ['by' => $world->first->id], 'b' => $two + ['by' => $world->second->id], 'facts' => ['descriptions' => [$one['description'], $two['description']]]];
            },
            'hold' => $holdPerson,
            'act' => $expense,
            'after' => fn (array $facts): array => $expensesPosted($facts['descriptions']),
        ],
        'two-bills-received' => [
            'title' => 'two bills of one supplier received, one each',
            'writers' => 'both',
            'prepare' => function (TwoWritersWorld $world, int $run): array {
                $one = $world->bill(590_000 + $run, false);
                $two = $world->bill(595_000 + $run, false);

                return [
                    'a' => ['bill' => $one->id, 'by' => $world->first->id],
                    'b' => ['bill' => $two->id, 'by' => $world->second->id],
                    'facts' => ['bills' => [$one->id, $two->id]],
                ];
            },
            'hold' => $holdBill,
            'act' => $receive,
            'after' => fn (array $facts): array => [...$received($facts['bills'][0]), ...$received($facts['bills'][1])],
        ],
    ];
}

/* ------------------------------------------------------------------------------------------------------------
 | A writer: one process, told what to hold and when to act.
 * ---------------------------------------------------------------------------------------------------------- */

if ($options['worker'] !== null) {
    ini_set('display_errors', 'stderr');
    $steps = twoWritersSteps();
    $held = [];
    $say = static function (array $answer): void {
        fwrite(STDOUT, TW_MARK.json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL);
        fflush(STDOUT);
    };
    $say(['ready' => true, 'pid' => getmypid()]);

    while (($line = fgets(STDIN)) !== false) {
        $command = json_decode($line, true);
        if (! is_array($command)) {
            continue;
        }
        if ($command['do'] === 'hold') {
            try {
                $held = $steps[$command['step']]['hold']($command['with']);
                $say(['holding' => true]);
            } catch (Throwable $problem) {
                $say(['holding' => false, 'message' => $problem::class.': '.$problem->getMessage()]);
            }

            continue;
        }
        if ($command['do'] !== 'act') {
            break;
        }

        // The gate: both writers have the same clock, and each sleeps up to its own instant.
        while (($left = $command['at'] - hrtime(true)) > 0) {
            if ($left > 300_000) {
                usleep((int) (($left - 200_000) / 1000));
            }
        }
        $started = hrtime(true);
        try {
            $answer = ['outcome' => 'wrote', 'value' => $steps[$command['step']]['act']($held)];
        } catch (ValidationException $refusal) {
            $answer = ['outcome' => 'refused', 'message' => implode(' ', $refusal->validator->errors()->all())];
        } catch (JournalPostingFailed|QuoteActionDenied $refusal) {
            $answer = ['outcome' => 'refused', 'message' => $refusal->getMessage()];
        } catch (AuthorizationException|HttpExceptionInterface $refusal) {
            $answer = ['outcome' => 'refused', 'message' => class_basename($refusal).': '.$refusal->getMessage()];
        } catch (Throwable $problem) {
            $text = $problem->getMessage();
            $kind = match (true) {
                (bool) preg_match('/Deadlock found|\b1213\b/', $text) => 'deadlock',
                (bool) preg_match('/Lock wait timeout|\b1205\b/', $text) => 'the wait for a lock ran out',
                (bool) preg_match('/Duplicate entry|\b1062\b/', $text) => 'a key met twice',
                $problem instanceof PDOException => 'database',
                default => 'exception',
            };
            $answer = ['outcome' => 'error', 'kind' => $kind, 'message' => class_basename($problem).': '.Str::limit((string) preg_replace('/\s+/', ' ', $text), 260)];
        }
        $answer['started'] = $started;
        $answer['ended'] = hrtime(true);

        // An action that leaves its transaction open would carry it into the next request of a long-lived server.
        $answer['left_open'] = DB::transactionLevel();
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        $say($answer);
    }

    exit(0);
}

/* ------------------------------------------------------------------------------------------------------------
 | The records of a run.
 * ---------------------------------------------------------------------------------------------------------- */

final class TwoWritersWorld
{
    public User $first;

    public User $second;

    private Vendor $vendor;

    private int $count = 0;

    /** @var array<string, int> */
    private array $accounts = [];

    public function __construct()
    {
        $this->first = $this->person('first');
        $this->second = $this->person('second');
        $this->vendor = Vendor::query()->create(['name' => 'TWO WRITERS supplier', 'payment_terms_days' => 15, 'is_active' => true]);
        NumberSequence::query()->create(['key' => TW_SERIES, 'prefix' => 'TW', 'next_number' => 1, 'padding' => 0, 'format' => '{prefix}-{number}', 'reset' => 'never']);
    }

    public function client(): Contact
    {
        $n = ++$this->count;

        return Contact::query()->create(['name' => "TWO WRITERS client {$n}", 'phone' => '+92300'.str_pad((string) $n, 7, '0', STR_PAD_LEFT), 'city' => 'Lahore', 'client_type' => 'individual', 'source' => 'website_form']);
    }

    /** An invoice as the actions leave one: issued and in the ledger, or a draft, or issued and not yet posted. */
    public function invoice(Contact $client, int $total, InvoiceStatus $status = InvoiceStatus::Issued, bool $posted = true): Invoice
    {
        $n = ++$this->count;
        $invoice = Invoice::query()->create([
            'number' => "TW-INV-{$n}", 'contact_id' => $client->id, 'business_line' => BusinessLine::Furniture,
            'kind' => InvoiceKind::Manual, 'status' => $status, 'currency' => 'PKR',
            'subtotal_minor' => $total, 'total_minor' => $total, 'paid_minor' => 0, 'balance_minor' => $total,
            'issue_date' => $status === InvoiceStatus::Draft ? null : today(), 'due_date' => today()->addDays(10),
        ]);
        $invoice->items()->create(['position' => 0, 'description' => 'Two writers', 'unit' => 'job', 'qty' => '1', 'rate_minor' => $total, 'line_total_minor' => $total]);
        if ($posted) {
            app(PostInvoiceToLedger::class)->handle($invoice, $this->first);
        }

        return $invoice;
    }

    public function payment(Invoice $invoice, int $amount, PaymentStatus $status, bool $forThisInvoice = true): Payment
    {
        return Payment::query()->create([
            'contact_id' => $invoice->contact_id, 'invoice_id' => $forThisInvoice ? $invoice->id : null, 'amount_minor' => $amount,
            'received_on' => today(), 'method' => PaymentMethod::Cash, 'status' => $status, 'submitted_by' => 'staff', 'created_by' => $this->first->id,
        ]);
    }

    public function vendor(): Vendor
    {
        $n = ++$this->count;

        return Vendor::query()->create(['name' => "TWO WRITERS supplier {$n}", 'payment_terms_days' => 15, 'is_active' => true]);
    }

    /** A supplier's bill: received and in the ledger, or still a draft. */
    public function bill(int $total, bool $received = true): Bill
    {
        $n = ++$this->count;
        $bill = Bill::query()->create([
            'number' => "TW-BILL-{$n}", 'vendor_id' => $this->vendor->id, 'bill_date' => today()->toDateString(), 'due_date' => today()->addDays(15)->toDateString(),
            'status' => BillStatus::Draft, 'subtotal_minor' => $total, 'tax_minor' => 0, 'total_minor' => $total, 'created_by' => $this->first->id,
        ]);
        $bill->items()->create(['account_id' => $this->account('5010'), 'description' => 'Two writers', 'amount_minor' => $total, 'position' => 0]);

        return $received ? app(ReceiveBill::class)->handle($bill, $this->first) : $bill->refresh();
    }

    /** A journal entry typed by hand, as the journal screen posts one. */
    public function entry(User $by, int $amount, string $reference): JournalEntry
    {
        return app(PostJournalEntry::class)->handle($by, [
            ['account_id' => $this->account('1010'), 'debit_minor' => $amount, 'memo' => 'Two writers'],
            ['account_id' => $this->account('3010'), 'credit_minor' => $amount, 'memo' => 'Two writers'],
        ], ['business_date' => today()->toDateString(), 'reference' => $reference, 'note' => 'Two writers at once.']);
    }

    public function account(string $code): int
    {
        return $this->accounts[$code] ??= (int) Account::query()->where('code', $code)->where('is_active', true)->valueOrFail('id');
    }

    public function nextOfSeries(): int
    {
        return (int) DB::table('number_sequences')->where('key', TW_SERIES)->value('next_number');
    }

    private function person(string $which): User
    {
        $user = User::query()->forceCreate([
            'name' => "TWO WRITERS {$which}", 'email' => "two-writers-{$which}@woodex.test", 'password' => Hash::make(Str::random(40)),
            'email_verified_at' => now(), 'is_active' => true, 'last_login_at' => now(),
        ]);
        $user->assignRole('owner');

        return $user;
    }
}

/* ------------------------------------------------------------------------------------------------------------
 | What must hold after every run, whatever the step was.
 * ---------------------------------------------------------------------------------------------------------- */

/**
 * What this run made wrong in the books. Each question counts the rows that are wrong; a run is charged with a
 * question whose count rose while it ran, and is shown the newest of those rows. So a fault is reported once, by
 * the run and the step that made it, and the runs and steps after it are judged on what they do themselves.
 * (Asked as "is anything wrong?", the first invoice paid twice was reported again by every later run, and the
 * next step stopped at its first run under that invoice's name: a reader was told the wrong step.)
 *
 * @return list<string>
 */
function twoWritersBooks(): array
{
    /** @var array<string, int> $known what was wrong when the run before this one ended */
    static $known = [];
    $faults = [];
    $ask = static function (string $what, string $sql, string $newest) use (&$faults, &$known): void {
        $wrong = (int) DB::selectOne("SELECT COUNT(*) AS wrong FROM ({$sql}) AS wrong_rows")->wrong;
        if ($wrong > ($known[$what] ?? 0)) {
            $rows = DB::select("{$sql} ORDER BY {$newest} DESC LIMIT 3");
            $faults[] = $what.': '.implode('; ', array_map(fn (object $row): string => implode(' ', array_map('strval', (array) $row)), $rows));
        }
        $known[$what] = $wrong;
    };
    $differs = static function (string $what, int $by, string $words) use (&$faults, &$known): void {
        if ($by !== ($known[$what] ?? 0)) {
            $faults[] = $words;
        }
        $known[$what] = $by;
    };

    $ask('a journal entry does not balance', 'SELECT e.entry_no, COALESCE(SUM(l.debit_minor), 0) AS debit, COALESCE(SUM(l.credit_minor), 0) AS credit FROM journal_entries e LEFT JOIN journal_lines l ON l.journal_entry_id = e.id GROUP BY e.id, e.entry_no HAVING COALESCE(SUM(l.debit_minor), 0) <> COALESCE(SUM(l.credit_minor), 0) OR COUNT(l.id) < 2', 'e.id');
    $ask('a number of a journal entry is given twice', 'SELECT entry_no FROM journal_entries GROUP BY entry_no HAVING COUNT(*) > 1', 'MAX(id)');
    $ask('one document is in the journal twice', 'SELECT source_type, source_id, COUNT(*) AS times FROM journal_entries WHERE source_type IS NOT NULL GROUP BY source_type, source_id HAVING COUNT(*) > 1', 'MAX(id)');
    $ask('a journal entry is reversed twice', 'SELECT reverses_entry_id, COUNT(*) AS times FROM journal_entries WHERE reverses_entry_id IS NOT NULL GROUP BY reverses_entry_id HAVING COUNT(*) > 1', 'MAX(id)');
    $ask('a reversal and its entry do not name each other', 'SELECT e.entry_no FROM journal_entries e JOIN journal_entries r ON r.reverses_entry_id = e.id WHERE e.reversed_by_entry_id IS NULL OR e.reversed_by_entry_id <> r.id', 'r.id');
    $ask('an invoice is paid beyond its total', 'SELECT number, paid_minor, balance_minor, total_minor FROM invoices WHERE paid_minor > total_minor OR balance_minor < 0 OR (status <> \'void\' AND paid_minor + balance_minor <> total_minor)', 'id');
    $ask('an invoice and its allocations disagree', 'SELECT i.number, i.paid_minor, COALESCE(SUM(a.amount_minor), 0) AS allocated, i.total_minor FROM invoices i LEFT JOIN payment_allocations a ON a.invoice_id = i.id GROUP BY i.id, i.number, i.paid_minor, i.total_minor HAVING COALESCE(SUM(a.amount_minor), 0) <> i.paid_minor OR COALESCE(SUM(a.amount_minor), 0) > i.total_minor', 'i.id');
    $ask('a payment is allocated beyond its amount', 'SELECT p.id, p.amount_minor, SUM(a.amount_minor) AS allocated FROM payments p JOIN payment_allocations a ON a.payment_id = p.id GROUP BY p.id, p.amount_minor HAVING SUM(a.amount_minor) > p.amount_minor', 'p.id');
    $ask('a payment that is not verified is allocated', "SELECT p.id, p.status FROM payments p JOIN payment_allocations a ON a.payment_id = p.id WHERE p.status <> 'verified'", 'a.id');
    $ask('a receipt number is given twice', 'SELECT receipt_number FROM payments WHERE receipt_number IS NOT NULL GROUP BY receipt_number HAVING COUNT(*) > 1', 'MAX(id)');
    $ask('a receipted payment is not in the journal', "SELECT p.id, p.receipt_number FROM payments p LEFT JOIN journal_entries e ON e.source_type = 'payment' AND e.source_id = p.id WHERE p.receipt_number IS NOT NULL AND e.id IS NULL", 'p.id');
    $ask('an expense is not in the journal', 'SELECT id, description FROM expenses WHERE journal_entry_id IS NULL', 'id');
    $ask('a bill and its payments disagree', 'SELECT b.number, b.paid_minor, COALESCE(SUM(p.amount_minor), 0) AS paid, b.total_minor FROM bills b LEFT JOIN bill_payments p ON p.bill_id = b.id GROUP BY b.id, b.number, b.paid_minor, b.total_minor HAVING COALESCE(SUM(p.amount_minor), 0) <> b.paid_minor OR COALESCE(SUM(p.amount_minor), 0) > b.total_minor', 'b.id');

    // No number left out: a number is taken inside the transaction that uses it, so a refusal gives it back.
    $year = now()->format('Y');
    foreach (['journal' => ['journal_entries', 'entry_no'], 'receipt' => ['payments', 'receipt_number']] as $key => [$table, $column]) {
        $sequence = DB::table('number_sequences')->where('key', $key)->first();
        $used = DB::table($table)->whereNotNull($column)->count();
        $next = $sequence->period === $year || $sequence->reset !== 'yearly' ? (int) $sequence->next_number : 1;
        $differs("series {$key}", $next - 1 - $used, "the series \"{$key}\" has given ".($next - 1)." numbers and {$used} documents carry one");
    }

    // The control accounts say what the documents say.
    $balance = static fn (string $code): int => (int) DB::table('journal_lines')->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
        ->where('chart_of_accounts.code', $code)->selectRaw('COALESCE(SUM(debit_minor), 0) - COALESCE(SUM(credit_minor), 0) AS balance')->value('balance');
    $posted = static fn (string $type) => fn ($query) => $query->selectRaw('1')->from('journal_entries')->where('source_type', $type)->whereNull('reversed_by_entry_id')
        ->whereColumn('source_id', $type === 'invoice' ? 'invoices.id' : ($type === 'payment' ? 'payments.id' : 'bills.id'));
    $invoiced = (int) DB::table('invoices')->whereExists($posted('invoice'))->sum('total_minor');
    $settled = (int) DB::table('payment_allocations')->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')->whereExists($posted('payment'))->sum('payment_allocations.amount_minor');
    $receivable = $balance('1100');
    $differs('receivable', $receivable - ($invoiced - $settled), "the receivable account stands at {$receivable} paisa; the posted invoices less the posted payments allocated to them come to ".($invoiced - $settled).' paisa');
    $billed = (int) DB::table('bills')->whereExists($posted('bill'))->sum('total_minor');
    $paid = (int) DB::table('bill_payments')->whereNotNull('journal_entry_id')->sum('amount_minor');
    $payable = -$balance('2010');
    $differs('payable', $payable - ($billed - $paid), "the payable account stands at {$payable} paisa; the posted bills less their posted payments come to ".($billed - $paid).' paisa');

    return $faults;
}

/* ------------------------------------------------------------------------------------------------------------
 | The command itself.
 * ---------------------------------------------------------------------------------------------------------- */

$steps = twoWritersSteps();

if ($options['list']) {
    foreach ($steps as $name => $step) {
        fwrite(STDOUT, str_pad($name, 28).($step['writers'] === 'one' ? 'one record, two writers: ' : 'a record each:           ').$step['title'].PHP_EOL);
    }
    exit(0);
}

$refuse = static function (string $why): never {
    fwrite(STDERR, "two-writers: refused. {$why}\n");
    exit(2);
};

$driver = DB::connection()->getDriverName();
$database = (string) DB::connection()->getDatabaseName();
if (! in_array($driver, ['mysql', 'mariadb'], true)) {
    $refuse("The connection is \"{$driver}\". SQLite lets one writer in at a time and cannot show the fault; give this command a MySQL or MariaDB database.");
}
if (! str_ends_with($database, '_test')) {
    $refuse("The database is \"{$database}\". Its name must end in \"_test\": this command empties it.");
}
if ($options['without-locks'] !== null) {
    $file = $base.'/'.$options['without-locks'];
    if (! is_file($file) || ! str_contains((string) file_get_contents($file), '->lockForUpdate()')) {
        $refuse("{$options['without-locks']} is not a file of this application with a lock in it.");
    }
}
$only = $options['only'] === null ? array_keys($steps) : array_values(array_filter(array_map('trim', explode(',', (string) $options['only']))));
if (($unknown = array_diff($only, array_keys($steps))) !== []) {
    $refuse('No step is called '.implode(', ', $unknown).'. See --list.');
}
$runs = max(1, (int) $options['runs']);

$server = (string) DB::selectOne('SELECT VERSION() AS version')->version;
$isolation = (string) (DB::select("SHOW VARIABLES WHERE Variable_name IN ('transaction_isolation', 'tx_isolation')")[0]->Value ?? '?');
$name = (str_contains(strtolower($server), 'mariadb') ? 'MariaDB ' : 'MySQL ').preg_replace('/[^0-9.].*$/', '', $server);

Artisan::call('migrate:fresh', ['--force' => true]);
Artisan::call('db:seed', ['--class' => ProductionSeeder::class, '--force' => true]);
$world = new TwoWritersWorld;

final class TwoWritersWriter
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes = [];

    public function __construct(public readonly int $number, string $scratch, ?string $withoutLocks)
    {
        // No code cache: a writer must run the files as they are now (and the copy without locks when given one).
        $command = [PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'memory_limit=1G', __FILE__, "--worker={$number}", ...($withoutLocks === null ? [] : ["--without-locks={$withoutLocks}"])];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $this->pipes, dirname(__DIR__, 2), [...getenv(), 'TWO_WRITERS_SCRATCH' => $scratch]);
        if (! is_resource($process)) {
            throw new RuntimeException("writer {$number} could not be started");
        }
        $this->process = $process;
    }

    /** @param  array<string, mixed>  $command */
    public function tell(array $command): void
    {
        fwrite($this->pipes[0], json_encode($command).PHP_EOL);
        fflush($this->pipes[0]);
    }

    /** @return array<string, mixed> */
    public function hear(int $seconds = 150): array
    {
        $until = microtime(true) + $seconds;
        while (($left = $until - microtime(true)) > 0) {
            $read = [$this->pipes[1]];
            $write = $except = null;
            if (stream_select($read, $write, $except, (int) $left, (int) (($left - (int) $left) * 1_000_000)) < 1) {
                continue;
            }
            $line = fgets($this->pipes[1]);
            if ($line === false) {
                throw new RuntimeException("writer {$this->number} stopped without an answer");
            }
            if (str_starts_with($line, TW_MARK)) {
                return (array) json_decode(substr($line, strlen(TW_MARK)), true);
            }
            fwrite(STDERR, "writer {$this->number} said: {$line}");
        }

        throw new RuntimeException("writer {$this->number} did not answer within {$seconds} seconds");
    }

    public function stop(): void
    {
        @fwrite($this->pipes[0], json_encode(['do' => 'stop']).PHP_EOL);
        @fclose($this->pipes[0]);
        @fclose($this->pipes[1]);
        proc_terminate($this->process);
    }
}

$writers = [new TwoWritersWriter(1, $scratch, $options['without-locks']), new TwoWritersWriter(2, $scratch, $options['without-locks'])];
$cleanUp = static function () use ($writers, $scratch): void {
    foreach ($writers as $writer) {
        $writer->stop();
    }
    exec('rm -rf '.escapeshellarg($scratch));
};

$say = static function (string $line): void {
    fwrite(STDOUT, $line.PHP_EOL);
};
$ms = static fn (int|float $nanoseconds): string => number_format($nanoseconds / 1_000_000, $nanoseconds < 10_000_000 ? 1 : 0).' ms';

try {
    foreach ($writers as $writer) {
        $writer->hear(60);
    }

    $say("Two writers on {$name} ({$isolation}), {$runs} runs a step".($options['without-locks'] === null ? '' : ", the writers holding a copy of {$options['without-locks']} without its locks"));
    $say('');

    $report = ['server' => $name, 'version' => $server, 'isolation' => $isolation, 'runs' => $runs, 'without_locks' => $options['without-locks'], 'steps' => []];
    $allFaults = [];

    foreach ($only as $stepName) {
        $step = $steps[$stepName];
        $alone = ['a' => 1, 'b' => 1];
        $told = [];
        $faults = [];
        $met = 0;
        $longest = 0;

        // Runs -3 to 0 are one writer after the other: what the step expects must hold then too, each writer has
        // done each part once before it is timed, and the last two say how long each part takes alone. Runs 1 to N
        // are the two at once; who starts first, and with which of the two parts, changes from run to run.
        for ($run = -3; $run <= $runs; $run++) {
            $made = $step['prepare']($world, $run);
            $order = intdiv($run + 4, 2) % 2 === 0 ? [0, 1] : [1, 0];
            $parts = abs($run) % 2 === 0 ? ['a', 'b'] : ['b', 'a'];
            $with = [$order[0] => $made[$parts[0]], $order[1] => $made[$parts[1]]];
            foreach ($writers as $index => $writer) {
                $writer->tell(['do' => 'hold', 'step' => $stepName, 'with' => $with[$index]]);
            }
            foreach ($writers as $writer) {
                $holding = $writer->hear();
                if (($holding['holding'] ?? false) !== true) {
                    throw new RuntimeException("writer {$writer->number} could not load the records of {$stepName}: ".($holding['message'] ?? '?'));
                }
            }

            $part = $run <= 0 ? null : TW_PARTS[intdiv($run - 1, 2) % count(TW_PARTS)];
            $lead = $part === null ? 0 : (int) ($part * $alone[$parts[0]]);
            $answers = [];
            if ($part === null) {
                foreach ($order as $index) {
                    $writers[$index]->tell(['do' => 'act', 'step' => $stepName, 'at' => 0]);
                    $answers[] = $writers[$index]->hear();
                }
                $alone[$parts[0]] = max(1, $answers[0]['ended'] - $answers[0]['started']);
            } else {
                $gate = hrtime(true) + 4_000_000;
                $writers[$order[0]]->tell(['do' => 'act', 'step' => $stepName, 'at' => $gate]);
                $writers[$order[1]]->tell(['do' => 'act', 'step' => $stepName, 'at' => $gate + $lead]);
                $answers = [$writers[$order[0]]->hear(), $writers[$order[1]]->hear()];
            }
            [$a, $b] = $answers;

            $runFaults = [];
            foreach (['first' => $a, 'second' => $b] as $who => $answer) {
                if ($answer['outcome'] === 'error') {
                    $runFaults[] = "the {$who} writer was answered by the database, not by the action ({$answer['kind']}): {$answer['message']}";
                } elseif ($answer['outcome'] === 'refused' && array_filter($step['refusals'] ?? [], fn (string $words): bool => (bool) preg_match($words, $answer['message'])) === []) {
                    $runFaults[] = "the {$who} writer was refused in words this step does not know: {$answer['message']}";
                }
                if (($answer['left_open'] ?? 0) > 0) {
                    $runFaults[] = "the {$who} writer's action left a transaction open";
                }
                $longest = max($longest, $answer['ended'] - $answer['started']);
            }
            $wrote = count(array_filter($answers, fn (array $answer): bool => $answer['outcome'] === 'wrote'));
            if ($step['writers'] === 'both' && $wrote !== 2) {
                $runFaults[] = "each writer had a record of its own and both were to write; {$wrote} did";
            }
            if ($step['writers'] === 'one' && $wrote === 0) {
                $runFaults[] = 'neither writer wrote';
            }
            if ($step['writers'] === 'one' && $wrote === 2) {
                $shown = $step['shown'] ?? [];
                $differs = array_filter($shown, fn (string $key): bool => ($a['value'][$key] ?? null) !== ($b['value'][$key] ?? null));
                if ($shown === [] || $differs !== []) {
                    $runFaults[] = 'both writers were told they had written'.($differs === [] ? '' : ', and were not shown the same: '.json_encode([$a['value'], $b['value']]));
                }
            }
            $runFaults = [...$runFaults, ...$step['after']($made['facts'], $a, $b), ...twoWritersBooks()];

            $how = $step['writers'] === 'both' && $wrote === 2 ? 'both wrote'
                : implode(' + ', array_map(fn (array $answer): string => match ($answer['outcome']) {
                    'wrote' => 'wrote',
                    'refused' => 'refused: '.Str::limit((string) preg_replace('/[A-Z]{2,}[-A-Z0-9]*\d+|\d[\d,.]*/', '#', $answer['message']), 90),
                    default => 'ERROR ('.$answer['kind'].')',
                }, $answers));
            if ($how === 'wrote + wrote') {
                $how = 'one wrote, the other was shown it';
            }
            if ($run <= 0) {
                if ($runFaults !== []) {
                    throw new RuntimeException("{$stepName} does not hold with one writer after the other, so two at once say nothing: ".implode(' | ', $runFaults));
                }

                continue;
            }
            $told[$how] = ($told[$how] ?? 0) + 1;
            $met += max($a['started'], $b['started']) < min($a['ended'], $b['ended']) ? 1 : 0;
            foreach (array_unique($runFaults) as $fault) {
                $faults[] = ['run' => $run, 'after' => $lead, 'fault' => $fault];
            }
        }

        arsort($told);
        $failed = count(array_unique(array_column($faults, 'run')));
        $line = str_pad($stepName, 27).' '.str_pad(($runs - $failed)." of {$runs} held", 15).' met in '.str_pad((string) $met, 3, ' ', STR_PAD_LEFT).'   alone '.str_pad($ms(max($alone)), 8, ' ', STR_PAD_LEFT).', longest '.str_pad($ms($longest), 8, ' ', STR_PAD_LEFT)
            .'   '.implode('; ', array_map(fn (string $how, int $times): string => "{$times} × {$how}", array_keys($told), $told));
        $say($line);
        foreach (array_slice($faults, 0, 4) as $fault) {
            $say("    FAULT in run {$fault['run']} (the second writer {$ms($fault['after'])} after the first): {$fault['fault']}");
        }
        if (count($faults) > 4) {
            $say('    … and '.(count($faults) - 4).' more');
        }
        $report['steps'][$stepName] = ['title' => $step['title'], 'writers' => $step['writers'], 'held' => $runs - $failed, 'met' => $met, 'alone_ms' => round(max($alone) / 1e6, 1), 'longest_ms' => round($longest / 1e6, 1), 'told' => $told, 'faults' => $faults];
        foreach ($faults as $fault) {
            $allFaults[] = "{$stepName}, run {$fault['run']}: {$fault['fault']}";
        }
    }
} catch (Throwable $problem) {
    $cleanUp();
    fwrite(STDERR, 'two-writers: STOPPED. '.$problem->getMessage().PHP_EOL);
    if (getenv('GITHUB_ACTIONS') === 'true') {
        $before = [];
        foreach ($report['steps'] ?? [] as $stepName => $step) {
            if ($step['faults'] !== []) {
                $before[] = "{$stepName}: {$step['held']} of {$runs} held, first \"".Str::limit($step['faults'][0]['fault'], 160).'"';
            }
        }
        $say('::error title=Two writers — stopped::'.str_replace(["\r", "\n"], ' ', $problem->getMessage()).($before === [] ? '' : ' — Before it stopped, these steps did not hold: '.implode(' · ', $before)));
    }
    exit(3);
}
$cleanUp();

$stepsRun = count($only);
$held = count(array_filter($report['steps'], fn (array $step): bool => $step['faults'] === []));
$report['faults'] = count($allFaults);
if ($options['json'] !== null) {
    file_put_contents((string) $options['json'], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);
}
$encode = static fn (string $text): string => str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $text);
$onGitHub = getenv('GITHUB_ACTIONS') === 'true';
$say('');

if ($options['expect-fault'] !== null) {
    $words = '/'.str_replace('/', '\/', (string) $options['expect-fault']).'/i';
    $found = array_values(array_filter($allFaults, fn (string $fault): bool => (bool) preg_match($words, $fault)));
    $runsFound = count(array_unique(array_map(fn (string $fault): string => (string) strstr($fault, ':', true), $found)));
    if ($found === []) {
        $say("THE CHECK DOES NOT BITE: without the locks of {$options['without-locks']} no run reported \"{$options['expect-fault']}\".");
        if ($onGitHub) {
            $say($encode("::error title=Two writers on {$name} — the check does not bite::Without the locks of {$options['without-locks']}, {$runs} runs of ".implode(', ', $only)." reported no \"{$options['expect-fault']}\"."));
        }
        exit(1);
    }
    $say("The check bites: without the locks of {$options['without-locks']}, {$runsFound} of {$runs} runs reported \"{$options['expect-fault']}\". The first: {$found[0]}");
    if ($onGitHub) {
        $say($encode("::notice title=Two writers on {$name} — the check bites::Without the locks of {$options['without-locks']}, {$runsFound} of {$runs} runs of ".implode(', ', $only)." reported \"{$options['expect-fault']}\". The first: {$found[0]}"));
    }
    exit(0);
}

if ($allFaults === []) {
    $say("All {$stepsRun} steps held in every one of their {$runs} runs.");
    if ($onGitHub) {
        $lines = array_map(fn (string $stepName, array $step): string => "{$stepName}: {$step['held']} of {$runs} held, the two met in {$step['met']} · ".implode('; ', array_map(fn (string $how, int $times): string => "{$times} × {$how}", array_keys($step['told']), $step['told'])), array_keys($report['steps']), $report['steps']);
        $say($encode("::notice title=Two writers on {$name}::{$stepsRun} steps, {$runs} runs each, all held ({$isolation}).\n".implode("\n", $lines)));
    }
    exit(0);
}

$say("{$held} of {$stepsRun} steps held; ".count($allFaults).' faults.');
if ($onGitHub) {
    // First of all one line for every step. GitHub shows no more than ten errors of one job step, and to a reader
    // without the run's log or its artifact they are all there is: with eleven steps failing, the eleventh would
    // not exist. "By the database" counts the runs in which the database ended a writer (a deadlock, a wait that
    // ran out); the others are faults in what was written.
    $table = [];
    foreach ($report['steps'] as $stepName => $step) {
        if ($step['faults'] === []) {
            continue;
        }
        $byDatabase = count(array_unique(array_column(array_filter($step['faults'], fn (array $fault): bool => str_contains($fault['fault'], 'was answered by the database')), 'run')));
        $table[] = "{$stepName}: {$step['held']} of {$runs} held".($byDatabase > 0 ? ", {$byDatabase} runs ended by the database" : '');
    }
    $heldNames = array_keys(array_filter($report['steps'], fn (array $step): bool => $step['faults'] === []));
    $say($encode("::error title=Two writers on {$name} — ".($stepsRun - $held)." of {$stepsRun} steps did not hold::{$runs} runs each ({$isolation}).\n".implode("\n", $table)."\nHeld in every run: ".($heldNames === [] ? 'none' : implode(', ', $heldNames)).'.'));
    foreach ($report['steps'] as $stepName => $step) {
        if ($step['faults'] !== []) {
            $say($encode("::error title=Two writers on {$name} — {$stepName}::{$step['held']} of {$runs} runs held. ".implode("\n", array_map(fn (array $fault): string => "run {$fault['run']}: {$fault['fault']}", array_slice($step['faults'], 0, 6)))));
        }
    }
}
exit(1);
