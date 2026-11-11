<?php

declare(strict_types=1);

use App\Domain\Accounts\Actions\PostInvoiceToLedger;
use App\Domain\Accounts\Actions\PostPaymentToLedger;
use App\Domain\Accounts\Exceptions\JournalPostingFailed;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\AccountingPeriod;
use App\Domain\Accounts\Models\JournalEntry;
use App\Domain\Accounts\Models\JournalLine;
use App\Domain\Accounts\Models\PaymentMethodAccount;
use App\Domain\Accounts\Reports\PaymentJournalReconciliation;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Actions\RecordPayment;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\PaymentAllocation;
use App\Domain\Sales\Services\InvoiceFreezer;
use App\Domain\Sales\Services\InvoicePdf;
use App\Livewire\Admin\Sales\PaymentsIndex;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 28)->setTime(11, 0));
    Http::preventStrayRequests();
    Storage::fake('local');
    Mail::fake();
    Notification::fake();
    $this->owner = $this->userWithRole('owner');
    $this->accountant = $this->userWithRole('accountant');
    $this->client = Contact::factory()->create(['email' => 'qa@example.test']);
});

function repair46Invoice(BusinessLine $line = BusinessLine::Interior, InvoiceStatus $status = InvoiceStatus::Issued): Invoice
{
    $invoice = Invoice::create(['number' => 'ISOLATED-REPAIR-46', 'contact_id' => test()->client->id,
        'business_line' => $line, 'status' => $status, 'issue_date' => today(), 'due_date' => today()->addDays(10),
        'subtotal_minor' => 10000, 'total_minor' => 10000, 'paid_minor' => 0, 'balance_minor' => 10000]);
    $invoice->items()->create(['position' => 0, 'description' => 'QA work', 'unit' => 'job', 'qty' => '1', 'rate_minor' => 10000, 'line_total_minor' => 10000]);

    return $invoice;
}

function repair46Payment(string $status = 'verified'): Payment
{
    return Payment::create(['contact_id' => test()->client->id, 'amount_minor' => 5000,
        'received_on' => today(), 'method' => 'cash', 'status' => $status, 'submitted_by' => 'staff']);
}

function repair46Record(array $overrides = []): Payment
{
    return app(RecordPayment::class)->handle(array_replace(['contact_id' => test()->client->id,
        'amount_minor' => 5000, 'received_on' => today()->toDateString(), 'method' => 'cash'], $overrides), test()->accountant);
}

it('posts the staff Payments form atomically for both brands without widening accountant access', function (BusinessLine $line) {
    $invoice = repair46Invoice($line);
    app(PostInvoiceToLedger::class)->handle($invoice, $this->owner);
    Livewire::actingAs($this->accountant)->test(PaymentsIndex::class)
        ->set('contactId', $invoice->contact_id)->set('invoiceId', $invoice->id)
        ->set('bankAccountId', null)->set('amount', '50.00')->set('method', 'cash')->call('save')->assertHasNoErrors();
    $payment = Payment::query()->sole();
    $entry = JournalEntry::where('source_type', 'payment')->where('source_id', $payment->id)->sole();
    $ar = Account::where('code', '1100')->sole();
    $net = (int) JournalLine::where('account_id', $ar->id)->sum('debit_minor') - (int) JournalLine::where('account_id', $ar->id)->sum('credit_minor');
    expect($payment->receipt_number)->not->toBeNull()
        ->and($invoice->refresh()->balance_minor)->toBe(5000)->and($net)->toBe(5000)
        ->and((int) $entry->lines()->sum('debit_minor'))->toBe(5000)
        ->and($this->accountant->can('accounts.view'))->toBeFalse();
})->with([BusinessLine::Interior, BusinessLine::Furniture]);

it('shows a closed-period form error and retains input without committing money or receipts', function () {
    $invoice = repair46Invoice();
    AccountingPeriod::query()->covering(today()->toDateString())->update(['closed_at' => now()]);
    Livewire::actingAs($this->accountant)->test(PaymentsIndex::class)
        ->set('showForm', true)->set('contactId', $invoice->contact_id)->set('invoiceId', $invoice->id)
        ->set('bankAccountId', null)->set('amount', '50.00')->set('method', 'cash')->call('save')
        ->assertHasErrors('amount')->assertSet('amount', '50.00')->assertSet('showForm', true);
    expect(Payment::count())->toBe(0)->and(PaymentAllocation::count())->toBe(0)
        ->and($invoice->refresh()->paid_minor)->toBe(0)->and(JournalEntry::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('rolls back staff receipt allocation and numbering when the method mapping is missing', function () {
    $invoice = repair46Invoice();
    PaymentMethodAccount::where('method', 'cash')->delete();
    expect(fn () => repair46Record(['invoice_id' => $invoice->id]))->toThrow(JournalPostingFailed::class);
    expect(Payment::count())->toBe(0)->and(PaymentAllocation::count())->toBe(0)
        ->and($invoice->refresh()->paid_minor)->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('posts explicit allocation and unallocated credit as separate credits in one balanced entry', function () {
    $invoice = repair46Invoice();
    $payment = repair46Record(['amount_minor' => 15000, 'allocations' => [$invoice->id => 6000]]);
    $entry = JournalEntry::with('lines.account')->where('source_type', 'payment')->where('source_id', $payment->id)->sole();
    expect((int) $entry->lines->where('account.code', '1100')->sum('credit_minor'))->toBe(6000)
        ->and((int) $entry->lines->where('account.code', '1150')->sum('credit_minor'))->toBe(9000)
        ->and((int) $entry->lines->sum('debit_minor'))->toBe(15000)
        ->and((int) $entry->lines->sum('credit_minor'))->toBe(15000);
});

it('rejects every unverified status at the posting boundary', function (string $status) {
    expect(fn () => app(PostPaymentToLedger::class)->handle(repair46Payment($status), $this->owner))
        ->toThrow(JournalPostingFailed::class);
    expect(JournalEntry::count())->toBe(0);
})->with(['pending_verification', 'rejected', 'refunded']);

it('reloads stale payment state and rejects a stale verified instance', function () {
    $payment = repair46Payment();
    Payment::whereKey($payment->id)->update(['status' => 'pending_verification']);
    expect(fn () => app(PostPaymentToLedger::class)->handle($payment, $this->owner))->toThrow(JournalPostingFailed::class);
    expect(JournalEntry::count())->toBe(0);
});

it('reloads current amount and state rather than posting stale values', function () {
    $payment = repair46Payment('pending_verification');
    Payment::whereKey($payment->id)->update(['status' => 'verified', 'amount_minor' => 7000]);
    $entry = app(PostPaymentToLedger::class)->handle($payment, $this->owner);
    expect((int) $entry->lines()->sum('debit_minor'))->toBe(7000);
});

it('returns the same posted journal on retry even after its period closes', function () {
    $payment = repair46Record();
    $entry = JournalEntry::where('source_type', 'payment')->sole();
    AccountingPeriod::query()->covering(today()->toDateString())->update(['closed_at' => now()]);
    expect(app(PostPaymentToLedger::class)->handle($payment, $this->owner)->id)->toBe($entry->id)
        ->and(JournalEntry::where('source_type', 'payment')->count())->toBe(1);
});

it('removes newly frozen invoice PDFs when issue fails or an outer transaction rolls back', function (bool $outer) {
    $invoice = repair46Invoice(status: InvoiceStatus::Draft);
    if (! $outer) {
        AccountingPeriod::query()->covering(today()->toDateString())->update(['closed_at' => now()]);
    }
    expect(fn () => DB::transaction(function () use ($invoice, $outer) {
        app(IssueInvoice::class)->handle($invoice, $this->owner);
        if ($outer) {
            throw new RuntimeException('Deliberate outer rollback');
        }
    }))->toThrow($outer ? RuntimeException::class : JournalPostingFailed::class);
    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Draft)->and($invoice->pdf_path)->toBeNull()
        ->and(JournalEntry::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([false, true]);

it('does not delete or change an existing valid frozen invoice on outer rollback', function () {
    $invoice = app(IssueInvoice::class)->handle(repair46Invoice(status: InvoiceStatus::Draft), $this->owner);
    $hash = hash('sha256', Storage::disk('local')->get($invoice->pdf_path));
    expect(fn () => DB::transaction(function () use ($invoice) {
        app(InvoiceFreezer::class)->freeze($invoice);
        throw new RuntimeException('Deliberate outer rollback');
    }))->toThrow(RuntimeException::class);
    expect(hash('sha256', Storage::disk('local')->get($invoice->pdf_path)))->toBe($hash);
});

it('refuses to overwrite or delete an existing unreferenced invoice artifact', function () {
    $invoice = repair46Invoice(status: InvoiceStatus::Draft);
    $path = 'invoices/'.$invoice->public_id.'/'.InvoicePdf::filename($invoice);
    Storage::disk('local')->put($path, 'existing unreferenced artifact');
    expect(fn () => app(IssueInvoice::class)->handle($invoice, $this->owner))->toThrow(RuntimeException::class, 'reviewed');
    expect(Storage::disk('local')->get($path))->toBe('existing unreferenced artifact')
        ->and($invoice->refresh()->status)->toBe(InvoiceStatus::Draft);
});

it('removes a new staff receipt and all financial writes on an outer rollback', function () {
    $invoice = repair46Invoice();
    expect(fn () => DB::transaction(function () use ($invoice) {
        repair46Record(['invoice_id' => $invoice->id]);
        throw new RuntimeException('Deliberate outer rollback');
    }))->toThrow(RuntimeException::class);
    expect(Payment::count())->toBe(0)->and(PaymentAllocation::count())->toBe(0)->and(JournalEntry::count())->toBe(0)
        ->and($invoice->refresh()->paid_minor)->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('reports missing verified payment journals without changing or posting historical records', function () {
    $missing = repair46Payment();
    repair46Payment('pending_verification');
    repair46Record();
    $before = $missing->refresh()->getAttributes();
    $count = JournalEntry::count();
    DB::enableQueryLog();
    $summary = app(PaymentJournalReconciliation::class)->summary();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($summary)->toBe(['currency' => 'PKR', 'verified_count' => 2, 'missing_journal_count' => 1,
        'missing_amount_minor' => 5000, 'manual_review_required' => true])
        ->and($missing->refresh()->getAttributes())->toBe($before)->and(JournalEntry::count())->toBe($count);
    foreach ($queries as $query) {
        expect(strtolower(ltrim($query['query'])))->toStartWith('select');
    }
    expect(Artisan::call('accounts:reconcile-payments'))->toBe(0);
    expect(Artisan::output())->toContain('READ ONLY', '"missing_journal_count":1');
    expect(JournalEntry::count())->toBe($count);
});

it('includes archived verified payments in the read-only completeness review', function () {
    $payment = repair46Payment();
    $payment->delete();
    expect(app(PaymentJournalReconciliation::class)->summary()['missing_journal_count'])->toBe(1)
        ->and(Payment::withTrashed()->findOrFail($payment->id)->trashed())->toBeTrue()
        ->and(JournalEntry::count())->toBe(0);
});

it('preserves the existing record-only staff permission without requiring verification or ledger access', function () {
    $staff = $this->userWithRole('sales');
    $staff->givePermissionTo('payments.record');
    expect($staff->can('payments.verify'))->toBeFalse()->and($staff->can('accounts.view'))->toBeFalse();
    $payment = app(RecordPayment::class)->handle(['contact_id' => $this->client->id,
        'amount_minor' => 5000, 'received_on' => today()->toDateString(), 'method' => 'cash'], $staff);
    expect($payment->status->value)->toBe('verified')
        ->and(JournalEntry::where('source_type', 'payment')->where('source_id', $payment->id)->count())->toBe(1);
});

it('returns an empty completeness summary without creating financial rows', function () {
    expect(app(PaymentJournalReconciliation::class)->summary())->toBe(['currency' => 'PKR',
        'verified_count' => 0, 'missing_journal_count' => 0, 'missing_amount_minor' => 0,
        'manual_review_required' => false])->and(Payment::count())->toBe(0)->and(JournalEntry::count())->toBe(0);
});
