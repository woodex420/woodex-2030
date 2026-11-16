<?php

declare(strict_types=1);

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\PaymentAllocation;
use App\Domain\System\Control\PeriodPerformance;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\GlobalSearch;
use App\Livewire\Admin\Hubs\ReportsHub;
use App\Livewire\Admin\MyDay;
use App\Livewire\Admin\Reports\RevenueCollections;
use App\Livewire\Admin\Sales\Approvals;
use App\Livewire\Admin\System\Health;
use App\Models\User;
use App\Support\AdminNavigation;
use App\Support\Reports\ReportRange;
use App\Support\Reports\RevenueCollectionsReport;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('excludes draft and void invoice values from every issued revenue aggregation', function () {
    $contact = Contact::factory()->create();
    foreach (InvoiceStatus::cases() as $status) {
        Invoice::create(['number' => 'AUDIT-'.$status->value, 'contact_id' => $contact->id,
            'business_line' => BusinessLine::Furniture, 'status' => $status,
            'issue_date' => today()->toDateString(), 'due_date' => today()->addDays(12)->toDateString(), 'total_minor' => 100000,
            'paid_minor' => 0, 'balance_minor' => 100000]);
    }
    $report = app(RevenueCollectionsReport::class)->handle(ReportRange::fromPreset('today'));
    expect($report['totals']['invoiced_minor'])->toBe(300000)
        ->and(array_sum(array_column($report['months'], 'invoiced_minor')))->toBe(300000)
        ->and(array_sum(array_column($report['by_line'], 'invoiced_minor')))->toBe(300000);
});

it('rejects a forbidden report tab selected through a hydrated hub', function () {
    Livewire::actingAs($this->userWithRole('sales'))->test(ReportsHub::class)
        ->set('tab', 'revenue')->assertForbidden();
});

it('counts the whole My Day queue instead of just the twenty row preview', function () {
    $sales = $this->userWithRole('sales');
    Lead::factory()->count(23)->create(['owner_id' => $sales->id, 'first_response_at' => null,
        'next_action_at' => now()->subDay()]);
    Livewire::actingAs($sales)->test(MyDay::class)
        ->assertViewHas('queueTotals', fn ($totals) => $totals['uncontacted'] === 23)
        ->assertViewHas('uncontacted', fn ($rows) => $rows->count() === 20)
        ->assertViewHas('overdue', fn ($rows) => $rows->isEmpty());
});

it('reconciles today and business-line collections without counting unallocated cash twice', function () {
    $contact = Contact::factory()->create();
    $make = fn ($line, $number) => Invoice::create(['number' => $number, 'contact_id' => $contact->id,
        'business_line' => $line, 'status' => InvoiceStatus::Issued, 'issue_date' => today()->toDateString(),
        'total_minor' => 100000, 'paid_minor' => 0, 'balance_minor' => 100000]);
    $interior = $make(BusinessLine::Interior, 'AUDIT-INTERIOR');
    $furniture = $make(BusinessLine::Furniture, 'AUDIT-FURNITURE');
    $make(BusinessLine::Furniture, 'AUDIT-FUTURE')->update(['issue_date' => today()->addDay()->toDateString()]);
    $payment = Payment::create(['contact_id' => $contact->id, 'amount_minor' => 100000,
        'received_on' => today()->toDateString(), 'method' => 'cash', 'status' => 'verified', 'submitted_by' => 'staff']);
    foreach ([[$interior, 25000], [$furniture, 50000]] as [$invoice, $amount]) {
        PaymentAllocation::create(['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount_minor' => $amount]);
    }
    $reporter = app(RevenueCollectionsReport::class);
    $range = ReportRange::fromPreset('today');
    $all = $reporter->handle($range);
    $scoped = $reporter->handle($range, BusinessLine::Furniture);
    expect($all['totals']['collected_minor'])->toBe(100000)
        ->and($all['totals']['invoiced_minor'])->toBe(200000)
        ->and($scoped['totals']['collected_minor'])->toBe(50000)
        ->and($scoped['totals']['invoiced_minor'])->toBe(100000)
        ->and($scoped['months'][0]['collected_minor'])->toBe(50000)
        ->and($scoped['by_line'][0]['collected_minor'])->toBe(50000)
        ->and($scoped['by_method'][0]['amount_minor'])->toBe(50000)
        ->and($scoped['by_method'][0]['count'])->toBe(1);
});

it('shares scoped period values with the revenue report and CSV', function () {
    $owner = $this->userWithRole('owner');
    $contact = Contact::factory()->create();
    Invoice::create(['number' => 'AUDIT-SHARED', 'contact_id' => $contact->id, 'business_line' => 'furniture',
        'status' => 'issued', 'issue_date' => today()->toDateString(), 'total_minor' => 1234500]);
    $cards = app(PeriodPerformance::class)->cards($owner, ReportRange::fromPreset('today'), BusinessLine::Furniture);
    $issued = collect($cards)->firstWhere('label', 'Issued invoice value');
    expect($issued['href'])->toContain('line=furniture', 'preset=today')->and($issued['display'])->toContain('12,345');
    Livewire::actingAs($owner)->test(RevenueCollections::class)
        ->set('preset', 'today')->set('line', 'furniture')->assertSee('12,345')->assertSee('unallocated cash is excluded')
        ->call('export')->assertFileDownloaded();
});

it('does not query CRM data for an accountant dashboard', function () {
    $accountant = $this->userWithRole('accountant');
    DB::enableQueryLog();
    Livewire::actingAs($accountant)->test(Dashboard::class)
        ->assertSee('Business performance')->assertDontSee('New leads, last 14 days')->assertDontSee('New lead');
    $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
    expect($queries)->not->toContain('from "leads"', 'from "activities"');
    DB::disableQueryLog();
});

it('keeps finance out of sales dashboards and rejects direct system and report access', function () {
    $sales = $this->userWithRole('sales');
    Livewire::actingAs($sales)->test(Dashboard::class)->assertDontSee('Issued invoice value');
    $this->actingAs($sales)->get('/admin?tab=system')->assertForbidden();
    Livewire::test(RevenueCollections::class)->assertForbidden();
    Livewire::test(Health::class)->assertForbidden();
});

it('offers reviewers their own queues without granting quote approval', function () {
    $accountant = $this->userWithRole('accountant');
    Livewire::actingAs($accountant)->test(Approvals::class)
        ->assertSee('Payment verification')->assertViewHas('pending', null)->call('approve', 1)->assertForbidden();
    $this->actingAs($accountant)->get('/admin/approvals')->assertOk();
});

it('opens operations and real source reports while hiding unimplemented navigation', function () {
    $owner = $this->userWithRole('owner');
    $this->actingAs($owner)->get('/admin?tab=operations')->assertOk()->assertSee('Operations control')->assertSee('Accounts');
    $this->get('/admin/reports?tab=sources')->assertOk()->assertSee('Trial balance')->assertSee('Profit &amp; loss', false);
    expect(collect(AdminNavigation::for($owner))->pluck('items')->flatten(1)->pluck('label')->all())->not->toContain('Lead Ads');
});

it('rejects invalid filter URLs instead of returning a server error or unrelated totals', function () {
    $this->actingAs($this->userWithRole('owner'));
    $this->get('/admin?preset=invented')->assertStatus(422);
    $this->get('/admin?line=invented')->assertStatus(422);
    $this->get('/admin/reports?tab=revenue&preset=invented')->assertStatus(422);
    $this->get('/admin/reports?tab=revenue&line=invented')->assertStatus(422);
});

it('does not advertise forbidden CRM shortcuts in the global search palette', function () {
    Livewire::actingAs($this->userWithRole('editor'))->test(GlobalSearch::class)
        ->assertDontSee('My Day')->assertDontSee('Site visits')->assertDontSee('Pipeline')->assertDontSee('Contacts');
    Livewire::actingAs(User::factory()->create())->test(GlobalSearch::class)
        ->assertDontSee('Operations')->assertDontSee('Website pages');
});
