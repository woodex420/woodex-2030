<?php

declare(strict_types=1);

use App\Domain\Accounts\Actions\PayBill;
use App\Domain\Accounts\Actions\ReceiveBill;
use App\Domain\Accounts\Actions\ReverseJournalEntry;
use App\Domain\Accounts\Actions\SaveBill;
use App\Domain\Accounts\Actions\VoidBill;
use App\Domain\Accounts\Enums\BillStatus;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\Bill;
use App\Domain\Accounts\Models\Expense;
use App\Domain\Accounts\Models\JournalEntry;
use App\Domain\Accounts\Models\Vendor;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Services\ProjectCostSummary;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Models\Invoice;
use App\Livewire\Admin\Accounts\BillsIndex;
use App\Livewire\Admin\Projects\ProjectCosts;
use App\Livewire\Admin\Projects\ProjectShow;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
    $this->project = Project::query()->create(['code' => 'TEST-COST-UI', 'name' => 'Synthetic project costs', 'contact_id' => Contact::factory()->create()->id, 'business_line' => 'interior', 'stage' => 'planning', 'status' => 'active']);
    $this->vendor = Vendor::query()->create(['name' => 'Synthetic UI supplier', 'is_active' => true]);
    $this->account = Account::query()->where('code', '5010')->firstOrFail();
});

function projectCostForm(object $test, string $kind = 'bill'): Testable
{
    return Livewire::actingAs($test->owner)->test(ProjectCosts::class, ['projectId' => $test->project->id])
        ->call('newCost')->set('kind', $kind)->set('vendorId', $test->vendor->id)->set('accountId', $test->account->id)
        ->set('description', 'Synthetic materials')->set('reference', 'TEST-UI-SUP-1')->set('amountRupees', '12500.50');
}

function projectUiBill(object $test, string $number, int $net = 10000, int $tax = 0, BillStatus $status = BillStatus::Received): Bill
{
    return app(SaveBill::class)->handle([
        'vendor_id' => $test->vendor->id, 'number' => $number, 'bill_date' => today()->toDateString(),
        'project_id' => $test->project->id, 'tax_minor' => $tax,
    ], [['account_id' => $test->account->id, 'description' => 'Synthetic bill', 'amount_minor' => $net]], $test->owner, $status);
}

it('records either source from the project screen with exact paisa and safe replay', function (string $kind) {
    $screen = projectCostForm($this, $kind)->call('save')->assertHasNoErrors()->assertSet('showForm', false);
    $key = $screen->get('requestKey');
    $screen->call('save')->assertHasNoErrors()->assertSet('requestKey', $key);
    $source = $kind === 'bill' ? Bill::query()->sole() : Expense::query()->sole();
    expect($source->project_id)->toBe($this->project->id)->and($source->journalEntry()->sole()->totals()['debit_minor'])->toBe(1250050)
        ->and(JournalEntry::query()->count())->toBe(1)->and($this->project->expenses()->count())->toBe(0);
})->with(['bill', 'paid']);

it('keeps request identity across a source-kind toggle and shows the duplicate error', function () {
    $screen = projectCostForm($this)->call('save')->assertHasNoErrors();
    $key = $screen->get('requestKey');
    $screen->set('kind', 'paid')->call('openForm')->call('save')->assertHasErrors('reference')->assertSee('already recorded a different cost')->assertSet('requestKey', $key);
    expect(Bill::query()->count())->toBe(1)->and(Expense::query()->count())->toBe(0);
});

it('rotates request identity only for explicit new-cost intent', function () {
    $screen = projectCostForm($this)->call('save')->assertHasNoErrors();
    $old = $screen->get('requestKey');
    $screen->call('newCost')->assertSet('reference', '')->assertSet('showForm', true);
    expect($screen->get('requestKey'))->not->toBe($old);
});

it('maps validation to visible form fields without reserving money', function (string $field, mixed $value) {
    projectCostForm($this)->set($field, $value)->call('save')->assertHasErrors($field);
    expect(Bill::query()->count())->toBe(0)->and(Expense::query()->count())->toBe(0);
})->with([['vendorId', null], ['accountId', null], ['description', ''], ['reference', ''], ['amountRupees', '0'], ['spentOn', 'bad-date']]);

it('supports choosing a project in the hub but refuses entry without a project', function () {
    Livewire::actingAs($this->owner)->test(ProjectCosts::class)->call('openForm')->assertHasErrors('selectedProjectId')
        ->set('selectedProjectId', $this->project->id)->call('newCost')->assertSet('showForm', true)->assertSee($this->project->code);
});

it('requires both project visibility and account visibility', function (string $permission) {
    $user = $this->userWithRole('support');
    $user->syncRoles([]);
    $user->syncPermissions([$permission]);
    Livewire::actingAs($user)->test(ProjectCosts::class, ['projectId' => $this->project->id])->assertForbidden();
})->with(['projects.view', 'accounts.view']);

it('gives authorized readers totals but no cost writing controls', function () {
    $user = $this->userWithRole('support');
    $user->syncRoles([]);
    $user->syncPermissions(['projects.view', 'accounts.view']);
    Livewire::actingAs($user)->test(ProjectCosts::class, ['projectId' => $this->project->id])
        ->assertSee('Linked costs')->assertDontSee('New project cost')->assertDontSee('Issued customer invoices')->call('newCost')->assertForbidden();
});

it('mounts the existing hub tabs and project-local component without duplicate financial entry forms', function () {
    $this->actingAs($this->owner)->get(route('admin.projects.costs'))->assertOk()->assertSee('Choose a project')->assertSee('Supplier bills')->assertSee('Vendors');
    $this->get(route('admin.projects.costs', ['tab' => 'bills']))->assertOk()->assertSee('New bill');
    $quote = app(CreateQuote::class)->handle($this->project->contact, BusinessLine::Interior, $this->owner);
    $this->project->update(['quote_id' => $quote->id]);
    Livewire::actingAs($this->owner)->test(ProjectShow::class, ['project' => $this->project])
        ->assertSeeLivewire(ProjectCosts::class)->assertSee('Legacy project cost history')->assertDontSee('wire:submit.prevent="addExpense"', false);
});

it('keeps linked cost, tax, settlement and draft totals distinct without counting payments as new costs', function () {
    $bill = projectUiBill($this, 'TEST-SUM', 10000, 500);
    projectUiBill($this, 'TEST-DRAFT', 99900, 0, BillStatus::Draft);
    app(PayBill::class)->handle($bill, 2500, PaymentMethod::BankTransfer, $this->owner);
    projectCostForm($this, 'paid')->set('amountRupees', '50.25')->call('save')->assertHasNoErrors();
    $summary = app(ProjectCostSummary::class)->forProject($this->project);
    expect($summary['linked_cost_minor'])->toBe(15025)->and($summary['supplier_billed_minor'])->toBe(10500)
        ->and($summary['bill_tax_minor'])->toBe(500)->and($summary['supplier_paid_minor'])->toBe(2500)
        ->and($summary['supplier_outstanding_minor'])->toBe(8000)->and($summary['direct_paid_minor'])->toBe(5025)
        ->and($summary['draft_bills_minor'])->toBe(99900);
});

it('excludes voided bills and reversed expenses while retaining their visible history', function () {
    $bill = projectUiBill($this, 'TEST-VOID');
    app(VoidBill::class)->handle($bill, $this->owner, 'Synthetic correction');
    projectCostForm($this, 'paid')->call('save')->assertHasNoErrors();
    $expense = Expense::query()->sole();
    app(ReverseJournalEntry::class)->handle($expense->journalEntry()->sole(), $this->owner, 'Synthetic correction');
    $summary = app(ProjectCostSummary::class)->forProject($this->project);
    expect($summary['linked_cost_minor'])->toBe(0)->and($summary['supplier_outstanding_minor'])->toBe(0);
    Livewire::actingAs($this->owner)->test(ProjectCosts::class, ['projectId' => $this->project->id])->assertSee('TEST-VOID')->assertSee('Reversed — excluded');
});

it('separates legacy unposted records instead of treating them as paid or linked purchases', function () {
    $this->project->expenses()->create(['category' => 'material', 'description' => 'Synthetic legacy', 'amount_minor' => 4567, 'incurred_on' => today()->toDateString(), 'created_by' => $this->owner->id]);
    $summary = app(ProjectCostSummary::class)->forProject($this->project);
    expect($summary['linked_cost_minor'])->toBe(0)->and($summary['legacy_recorded_minor'])->toBe(4567)->and($summary['legacy_posted_minor'])->toBe(0);
    Livewire::actingAs($this->owner)->test(ProjectCosts::class, ['projectId' => $this->project->id])->assertSee('Legacy project costs need reconciliation')->assertSee('not assumed paid');
});

it('includes only issued customer invoices and separates credit notes', function () {
    foreach (['draft', 'void', 'issued', 'partially_paid', 'paid'] as $status) {
        Invoice::query()->create(['number' => 'TEST-CUST-'.$status, 'contact_id' => $this->project->contact_id, 'project_id' => $this->project->id, 'business_line' => 'interior', 'kind' => 'manual', 'status' => $status, 'total_minor' => 10000, 'paid_minor' => 2500, 'balance_minor' => 7500, 'created_by' => $this->owner->id]);
    }
    Invoice::query()->create(['number' => 'TEST-CUST-CREDIT', 'contact_id' => $this->project->contact_id, 'project_id' => $this->project->id, 'business_line' => 'interior', 'kind' => 'credit_note', 'status' => 'issued', 'total_minor' => 5000, 'created_by' => $this->owner->id]);
    $summary = app(ProjectCostSummary::class)->forProject($this->project);
    expect($summary['customer_billed_minor'])->toBe(30000)->and($summary['customer_received_minor'])->toBe(7500)
        ->and($summary['customer_outstanding_minor'])->toBe(22500)->and($summary['customer_credit_notes_minor'])->toBe(5000);
});

it('filters the existing bill screen and its cards by project including actual partial payments this month', function () {
    $bill = projectUiBill($this, 'TEST-FILTER');
    app(PayBill::class)->handle($bill, 2500, PaymentMethod::BankTransfer, $this->owner);
    app(PayBill::class)->handle($bill, 1000, PaymentMethod::BankTransfer, $this->owner, paidOn: today()->startOfMonth()->addMonth()->toDateString());
    $this->project = Project::query()->create(['code' => 'TEST-OTHER', 'name' => 'Other project', 'contact_id' => $this->project->contact_id, 'business_line' => 'furniture']);
    projectUiBill($this, 'TEST-OTHER-BILL');
    Livewire::actingAs($this->owner)->test(BillsIndex::class)->set('projectFilter', $bill->project_id)
        ->assertSee('TEST-FILTER')->assertDontSee('TEST-OTHER-BILL')->assertViewHas('outstanding', 6500)->assertViewHas('paidThisMonth', 2500);
});

it('preserves very large decimal bill amounts when prefilling edits and payments', function () {
    $bill = projectUiBill($this, 'TEST-LARGE', 9007199254740993, 0, BillStatus::Draft);
    Livewire::actingAs($this->owner)->test(BillsIndex::class)->call('openForm', $bill->id)->assertSet('lines.0.amountRupees', '90071992547409.93')->assertSet('projectId', $this->project->id);
    app(ReceiveBill::class)->handle($bill, $this->owner);
    Livewire::actingAs($this->owner)->test(BillsIndex::class)->call('openPay', $bill->id)->assertSet('payAmount', '90071992547409.93');
});
