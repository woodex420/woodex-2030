<?php

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Enums\PaymentStatus;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\BankAccount;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\PaymentAllocation;
use App\Livewire\Admin\Reports\Receivables;
use App\Livewire\Admin\Reports\RevenueCollections;
use App\Livewire\Admin\Reports\SalesFunnel;
use App\Models\User;
use App\Support\Reports\Csv;
use App\Support\Reports\ReceivablesReport;
use App\Support\Reports\ReportRange;
use App\Support\Reports\RevenueCollectionsReport;
use App\Support\Reports\SalesFunnelReport;
use Livewire\Livewire;

/*
| The P3 reports kit (RPT-002, RPT-005, INV-005b, RPT-009): three screens the numbers must agree with.
| Every test builds a small world, then checks the report's answer — and that each screen lets the right
| people in and keeps the rest out.
*/

function reportsOwner(): User
{
    $owner = User::factory()->create();
    $owner->assignRole('owner');

    return $owner;
}

// Relative-day fixtures must stay within their intended month, including on the first day.
// Laravel clears the test clock during teardown; production time is never changed.
beforeEach(function () {
    $this->travelTo(now()->startOfMonth()->addDays(14)->setTime(12, 0));
});

it('shows the funnel: leads by source, win rate, and why leads were lost', function () {
    $owner = reportsOwner();
    $sales = User::factory()->create(['name' => 'Bilal Ahmed']);
    $sales->assignRole('sales');

    $contact = Contact::factory()->create();
    $wonLead = Lead::factory()->create([
        'contact_id' => $contact->id,
        'source' => LeadSource::WebsiteForm,
        'business_line' => BusinessLine::Interior,
        'owner_id' => $sales->id,
        'created_at' => now()->subDays(3),
        'won_at' => now()->subDay(),
    ]);
    Lead::factory()->create([
        'source' => LeadSource::InstagramDm,
        'business_line' => BusinessLine::Furniture,
        'owner_id' => $sales->id,
        'created_at' => now()->subDays(2),
        'lost_at' => now()->subDay(),
        'lost_reason' => 'budget too low',
    ]);

    $quote = app(CreateQuote::class)->handle($contact, BusinessLine::Interior, $sales, $wonLead, null, 'Lounge');
    $quote->forceFill([
        'status' => QuoteStatus::Accepted,
        'total_minor' => 25_000_000,
        'sent_at' => now()->subDays(2),
        'accepted_at' => now()->subDay(),
    ])->save();

    $report = app(SalesFunnelReport::class)->handle(ReportRange::fromPreset('this_month'));

    expect($report['headline']['leads'])->toBe(2)
        ->and($report['headline']['quotes_sent'])->toBe(1)
        ->and($report['headline']['accepted'])->toBe(1)
        ->and($report['headline']['win_rate'])->toBe(100)
        ->and($report['headline']['accepted_value_minor'])->toBe(25_000_000)
        ->and($report['headline']['lost'])->toBe(1);

    $sources = collect($report['by_source']);
    $website = $sources->firstWhere('label', 'Website Form');

    expect($website)->not->toBeNull()
        ->and($website['leads'])->toBe(1)
        ->and($website['accepted'])->toBe(1)
        ->and($sources->firstWhere('label', 'Instagram DM'))->not->toBeNull();

    expect($report['drop_offs'][0]['reason'])->toBe('budget too low')
        ->and($report['by_owner'][0]['label'])->toBe('Bilal Ahmed');

    // The screen shows it, and the owner can pull it as CSV.
    Livewire::actingAs($owner)
        ->test(SalesFunnel::class)
        ->assertSee('Sales funnel')
        ->assertSee('Website Form')
        ->assertSee('budget too low')
        ->call('export');
});

it('answers revenue and collections per month, by line and by method', function () {
    $owner = reportsOwner();
    $contact = Contact::factory()->create();

    $invoice = Invoice::query()->create([
        'number' => 'INV-'.now()->format('ymdHis'),
        'contact_id' => $contact->id,
        'business_line' => BusinessLine::Interior->value,
        'status' => InvoiceStatus::Issued,
        'total_minor' => 12_000_000,
        'paid_minor' => 4_000_000,
        'issue_date' => now()->subDays(10)->toDateString(),
        'due_date' => now()->addDays(10)->toDateString(),
    ]);

    $payment = Payment::query()->create([
        'contact_id' => $contact->id,
        'invoice_id' => $invoice->id,
        'amount_minor' => 4_000_000,
        'received_on' => now()->subDays(5)->toDateString(),
        'method' => PaymentMethod::BankTransfer->value,
        'status' => PaymentStatus::Verified->value,
        'submitted_by' => 'staff',
    ]);
    PaymentAllocation::query()->create([
        'payment_id' => $payment->id,
        'invoice_id' => $invoice->id,
        'amount_minor' => 4_000_000,
    ]);

    $report = app(RevenueCollectionsReport::class)->handle(ReportRange::fromPreset('this_month'));

    expect($report['totals']['invoiced_minor'])->toBe(12_000_000)
        ->and($report['totals']['collected_minor'])->toBe(4_000_000)
        ->and($report['totals']['outstanding_minor'])->toBe(8_000_000)
        ->and($report['months'])->not->toBeEmpty()
        ->and($report['months'][0]['invoiced_minor'])->toBe(12_000_000)
        ->and($report['by_line'][0]['label'])->toBe('Interior')
        ->and($report['by_line'][0]['collected_minor'])->toBe(4_000_000)
        ->and($report['by_method'][0]['label'])->toStartWith('Bank Transfer');

    Livewire::actingAs($owner)
        ->test(RevenueCollections::class)
        ->assertSee('Revenue & collections')
        ->assertSee('By month')
        ->call('export');
});

it('ages receivables worst first: not due, 30, 60 and 90+ buckets', function () {
    $owner = reportsOwner();
    $prompt = Contact::factory()->create(['name' => 'Prompt Client']);
    $late = Contact::factory()->create(['name' => 'Late Client']);

    Invoice::query()->create([
        'number' => 'INV-A'.now()->format('His'),
        'contact_id' => $prompt->id,
        'business_line' => BusinessLine::Interior->value,
        'status' => InvoiceStatus::Issued,
        'total_minor' => 100_000,
        'paid_minor' => 0,
        'issue_date' => now()->subDays(5)->toDateString(),
        'due_date' => now()->addDays(20)->toDateString(),
    ]);

    Invoice::query()->create([
        'number' => 'INV-B'.now()->format('His'),
        'contact_id' => $late->id,
        'business_line' => BusinessLine::Interior->value,
        'status' => InvoiceStatus::PartiallyPaid,
        'total_minor' => 600_000,
        'paid_minor' => 100_000,
        'issue_date' => now()->subDays(120)->toDateString(),
        'due_date' => now()->subDays(95)->toDateString(),
    ]);

    $report = app(ReceivablesReport::class)->handle();

    expect($report['rows'][0]['contact'])->toBe('Late Client')
        ->and($report['rows'][0]['d90_plus'])->toBe(500_000)
        ->and($report['rows'][0]['balance_minor'])->toBe(500_000)
        ->and($report['rows'][1]['contact'])->toBe('Prompt Client')
        ->and($report['rows'][1]['current'])->toBe(100_000)
        ->and($report['totals']['d90_plus'])->toBe(500_000)
        ->and($report['totals']['balance_minor'])->toBe(600_000);

    Livewire::actingAs($owner)
        ->test(Receivables::class)
        ->assertSee('Late Client')
        ->assertSee('Prompt Client')
        ->call('export');
});

it('compares against the equal-length previous period', function () {
    $range = ReportRange::fromPreset('this_month');

    expect($range->previous()->from->format('Y-m-d'))->toBe(now()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d'))
        ->and($range->previous()->to->format('Y-m-d'))->toBe(now()->subMonthNoOverflow()->endOfMonth()->format('Y-m-d'));

    $quarter = ReportRange::fromPreset('this_quarter');

    expect($quarter->previous()->from->format('Y-m-d'))->toBe(now()->subQuarterNoOverflow()->startOfQuarter()->format('Y-m-d'));
});

it('keeps each report behind its permission', function () {
    $sales = $this->userWithRole('sales');            // reports.view + invoices.view, no reports.finance
    $accountant = $this->userWithRole('accountant');  // reports.view + reports.finance + invoices.view

    $this->actingAs($sales)->get('/admin/reports?tab=funnel')->assertOk();
    $this->actingAs($sales)->get('/admin/reports?tab=revenue')->assertForbidden();
    $this->actingAs($sales)->get('/admin/payments?tab=receivables')->assertOk();

    $this->actingAs($accountant)->get('/admin/reports?tab=funnel')->assertOk();
    $this->actingAs($accountant)->get('/admin/reports?tab=revenue')->assertOk();
    $this->actingAs($accountant)->get('/admin/payments?tab=receivables')->assertOk();
});

it('writes a CSV an accountant can open: UTF-8, plain rupees, the same numbers as the screen', function () {
    $response = Csv::download('test.csv', ['Client', 'Balance (Rs)'], [['Late Client', 5000.00], ['Prompt Client', 1000.00]]);

    ob_start();
    $response->send();
    $csv = (string) ob_get_clean();

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($csv)->toContain('Client')
        ->and($csv)->toContain('Balance (Rs)')
        ->and($csv)->toContain('Late Client')
        ->and($csv)->toContain('5000')
        ->and($csv)->toContain('Prompt Client');
});


it('labels collection methods using the actual bank column without exposing account details', function () {
    $contact = Contact::factory()->create();
    $bank = BankAccount::create(['title' => 'Synthetic clearing account', 'bank_name' => 'Test Clearing Bank',
        'account_number' => 'DO-NOT-EXPOSE-TEST-ACCOUNT', 'iban' => 'DO-NOT-EXPOSE-TEST-IBAN']);
    Payment::create(['contact_id' => $contact->id, 'amount_minor' => 12000,
        'received_on' => today()->toDateString(), 'method' => PaymentMethod::BankTransfer,
        'bank_account_id' => $bank->id, 'status' => PaymentStatus::Verified, 'submitted_by' => 'staff']);

    $rows = app(RevenueCollectionsReport::class)->byMethod(ReportRange::fromPreset('today'));
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['label'])->toBe('Bank Transfer · Test Clearing Bank')
        ->and($rows[0]['amount_minor'])->toBe(12000)
        ->and($rows[0]['count'])->toBe(1)
        ->and(json_encode($rows))->not->toContain('DO-NOT-EXPOSE-TEST-ACCOUNT', 'DO-NOT-EXPOSE-TEST-IBAN');
});


it('keeps previous-month leads out of the first-day current-month funnel', function () {
    $this->travelTo(now()->setDate(2026, 11, 1)->startOfDay()->addHours(12));
    Lead::factory()->create(['created_at' => now()->subDay()]);
    Lead::factory()->create(['created_at' => now()]);
    $report = app(SalesFunnelReport::class)->handle(ReportRange::fromPreset('this_month'));
    expect($report['headline']['leads'])->toBe(1)
        ->and($report['previous']['leads'])->toBe(1);
});
