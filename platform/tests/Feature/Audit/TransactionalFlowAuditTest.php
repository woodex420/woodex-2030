<?php

declare(strict_types=1);

use App\Domain\Accounts\Exceptions\JournalPostingFailed;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\AccountingPeriod;
use App\Domain\Accounts\Models\JournalEntry;
use App\Domain\Accounts\Models\JournalLine;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Projects\Actions\OpenProjectFromQuote;
use App\Domain\Projects\Models\Project;
use App\Domain\Sales\Actions\CollectPayment;
use App\Domain\Sales\Actions\CreateFurnitureInvoice;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\DecideQuoteApproval;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Actions\RequestQuoteApproval;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Actions\VerifyPayment;
use App\Domain\Sales\Actions\VoidInvoice;
use App\Domain\Sales\Enums\DiscountType;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Exceptions\QuoteActionDenied;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\PaymentAllocation;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuoteLinks;
use App\Support\Reports\ReportRange;
use App\Support\Reports\RevenueCollectionsReport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 28)->setTime(11, 0));
    Http::preventStrayRequests();
    Storage::fake('local');
    Mail::fake();
    Notification::fake();
    $this->owner = $this->userWithRole('owner');
    $this->sales = $this->userWithRole('sales');
    $this->manager = $this->userWithRole('sales_manager');
});

/** Native draft creation, pricing, discount approval and immutable send; no external delivery. */
function audit45SentQuote(BusinessLine $line): Quote
{
    $t = test();
    $lead = Lead::factory()->create(['owner_id' => $t->sales->id, 'business_line' => $line]);
    $lead->contact->update(['name' => 'Isolated QA client', 'email' => 'qa-client@example.test']);
    $quote = app(CreateQuote::class)->handle($lead->contact, $line, $t->sales, $lead, null, 'Isolated audit work');
    $editor = app(QuoteEditor::class);
    $section = $quote->sections()->first();
    $editor->addLine($section, ['product_name' => 'QA product', 'description' => 'QA work', 'unit' => 'job', 'qty' => '1', 'rate_minor' => 1_000_000]);
    $quote->items()->where('rate_minor', 0)->each(fn ($item) => $editor->deleteLine($item));
    $editor->updateDetails($quote, ['discount_type' => DiscountType::Percent, 'discount_value' => 800, 'tax_bp' => 1600]);
    $approval = app(RequestQuoteApproval::class)->handle($quote->refresh(), $t->sales);
    app(DecideQuoteApproval::class)->handle($approval, $t->manager, true);
    app(SendQuote::class)->handle($quote->refresh(), $t->sales);

    return $quote->refresh();
}

/** Accept through the signed HTTP contract, then use the brand's real billing workflow. */
function audit45IssuedInvoice(BusinessLine $line): Invoice
{
    $quote = audit45SentQuote($line);
    test()->post(QuoteLinks::signed('quotes.portal.accept', $quote), [
        'revision' => $quote->revision, 'name' => 'QA client', 'confirm' => '1',
    ])->assertRedirect();
    expect($quote->refresh()->status)->toBe(QuoteStatus::Accepted);
    if ($line === BusinessLine::Furniture) {
        expect($quote->invoices()->count())->toBe(0);
        $invoice = app(CreateFurnitureInvoice::class)->handle($quote, test()->owner);
        expect($invoice->status)->toBe(InvoiceStatus::Draft);

        return app(IssueInvoice::class)->handle($invoice, test()->owner);
    }

    return $quote->invoices()->sole();
}

function audit45ReceivableNet(): int
{
    $id = Account::query()->where('code', '1100')->sole()->id;

    return (int) JournalLine::query()->where('account_id', $id)->sum('debit_minor')
        - (int) JournalLine::query()->where('account_id', $id)->sum('credit_minor');
}

it('ties both brand workflows from approved quote to signed acceptance project receipts and ledger', function (BusinessLine $line) {
    $invoice = audit45IssuedInvoice($line);
    $quote = $invoice->quote;
    $version = $quote->versions()->sole();
    $frozenHash = hash('sha256', Storage::disk('local')->get($version->pdf_path));
    expect($frozenHash)->toBe($version->pdf_sha256)
        ->and($quote->contact->is_client)->toBeTrue()
        ->and($quote->lead->won_at)->not->toBeNull()
        ->and(Project::query()->where('quote_id', $quote->id)->count())->toBe(1)
        ->and($invoice->status)->toBe(InvoiceStatus::Issued)
        ->and(audit45ReceivableNet())->toBe($invoice->total_minor);
    $part = intdiv($invoice->total_minor, 3);
    $first = app(CollectPayment::class)->handle($invoice, $part, PaymentMethod::Cash, $this->owner);
    expect($invoice->refresh()->status)->toBe(InvoiceStatus::PartiallyPaid)
        ->and(audit45ReceivableNet())->toBe($invoice->balance_minor);
    app(VerifyPayment::class)->handle($first, $this->owner);
    $last = app(CollectPayment::class)->handle($invoice, $invoice->balance_minor, PaymentMethod::Cash, $this->owner);
    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->balance_minor)->toBe(0)
        ->and(audit45ReceivableNet())->toBe(0)
        ->and((int) PaymentAllocation::query()->sum('amount_minor'))->toBe($invoice->total_minor)
        ->and(JournalEntry::query()->where('source_type', 'payment')->count())->toBe(2)
        ->and($first->receipt_number)->not->toBe($last->receipt_number)
        ->and(JournalLine::query()->sum('debit_minor'))->toBe(JournalLine::query()->sum('credit_minor'))
        ->and(hash('sha256', Storage::disk('local')->get($version->pdf_path)))->toBe($frozenHash);
    $totals = app(RevenueCollectionsReport::class)->totals(ReportRange::fromPreset('today'), $line);
    expect($totals['collected_minor'])->toBe($invoice->total_minor)
        ->and($totals['invoiced_minor'])->toBe($invoice->total_minor);
})->with([BusinessLine::Interior, BusinessLine::Furniture]);

it('rolls back verified collection allocations receipts and journal when its period is closed', function () {
    $invoice = audit45IssuedInvoice(BusinessLine::Interior);
    $files = Storage::disk('local')->allFiles();
    AccountingPeriod::query()->covering(today()->toDateString())->update(['closed_at' => now()]);
    expect(fn () => app(CollectPayment::class)->handle($invoice, 10_000, PaymentMethod::Cash, $this->owner))
        ->toThrow(JournalPostingFailed::class);
    expect(Payment::query()->count())->toBe(0)
        ->and(PaymentAllocation::query()->count())->toBe(0)
        ->and($invoice->refresh()->paid_minor)->toBe(0)
        ->and(JournalEntry::query()->where('source_type', 'payment')->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe($files);
});

it('voids an unpaid invoice through a linked balanced reversal without destroying its frozen document', function () {
    $invoice = audit45IssuedInvoice(BusinessLine::Interior);
    $before = $invoice->getAttributes();
    $hash = hash('sha256', Storage::disk('local')->get($invoice->pdf_path));
    app(VoidInvoice::class)->handle($invoice, $this->owner, 'Isolated QA correction');
    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Void)
        ->and(audit45ReceivableNet())->toBe(0)
        ->and(JournalEntry::query()->whereNotNull('reverses_entry_id')->count())->toBe(1)
        ->and($invoice->pdf_path)->toBe($before['pdf_path'])
        ->and(hash('sha256', Storage::disk('local')->get($invoice->pdf_path)))->toBe($hash);
    expect(fn () => app(VoidInvoice::class)->handle($invoice, $this->owner, 'Duplicate correction'))
        ->toThrow(ValidationException::class);
    expect(JournalEntry::query()->whereNotNull('reverses_entry_id')->count())->toBe(1);
});

it('refuses invoice void after collection and preserves the receivable and both journals', function () {
    $invoice = audit45IssuedInvoice(BusinessLine::Interior);
    app(CollectPayment::class)->handle($invoice, 10_000, PaymentMethod::Cash, $this->owner);
    expect(fn () => app(VoidInvoice::class)->handle($invoice, $this->owner, 'Invalid paid void'))
        ->toThrow(ValidationException::class);
    expect($invoice->refresh()->status)->toBe(InvoiceStatus::PartiallyPaid)
        ->and(audit45ReceivableNet())->toBe($invoice->balance_minor)
        ->and(JournalEntry::query()->whereNotNull('reverses_entry_id')->count())->toBe(0);
});

it('does not recreate an archived project from the same accepted quotation', function () {
    $invoice = audit45IssuedInvoice(BusinessLine::Interior);
    $project = $invoice->quote->project;
    $project->delete();
    expect(fn () => app(OpenProjectFromQuote::class)->handle($invoice->quote))->toThrow(QuoteActionDenied::class);
    expect(Project::withTrashed()->where('quote_id', $invoice->quote_id)->count())->toBe(1);
});
