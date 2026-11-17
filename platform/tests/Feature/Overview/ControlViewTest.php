<?php

declare(strict_types=1);

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Projects\Enums\ProjectStage;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Models\Invoice;
use App\Domain\System\Control\ControlPanel;
use App\Domain\System\Enums\ApprovalStatus;
use App\Domain\System\Models\Approval;
use App\Domain\System\Models\ScheduledTaskRun;
use App\Livewire\Admin\Crm\LeadsIndex;
use App\Livewire\Admin\Dashboard;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

use function Pest\Livewire\livewire;

/*
| Deep QA slice D (docs/audit/31): the Overview is a control view, not a second report.
| One query per number, in ControlPanel; the dashboard tile, the "needs attention" line, the Reports
| strip and the Leads list filter all read it — so a count and the list under it cannot disagree, and
| no tile is a dead end (every one opens the screen that filters to the same rows).
*/

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 21)->setTime(10, 0));

    $this->owner = $this->userWithRole('owner');
    $this->sales = $this->userWithRole('sales');
    $this->accountant = $this->userWithRole('accountant');
});

function leadDueYesterday(User $owner, array $attributes = []): Lead
{
    return Lead::factory()->create(array_merge([
        'contact_id' => Contact::factory()->create()->id,
        'owner_id' => $owner->id,
        'business_line' => BusinessLine::Interior,
        'next_action_at' => now()->subDay(),
    ], $attributes));
}

function issuedInvoice(User $sales, array $attributes = []): Invoice
{
    $lead = Lead::factory()->create(['owner_id' => $sales->id]);

    return Invoice::query()->create(array_merge([
        'number' => 'INV-2026-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'contact_id' => $lead->contact_id,
        'lead_id' => $lead->id,
        'business_line' => BusinessLine::Interior->value,
        'issued_on' => now()->subDays(10)->toDateString(),
        'status' => InvoiceStatus::Issued,
        'due_date' => now()->subDays(3)->toDateString(),
        'subtotal_minor' => 5000000,
        'total_minor' => 5000000,
        'balance_minor' => 5000000,
        'currency' => 'PKR',
    ], $attributes));
}

it('opens the day with the six live numbers, each linked to the screen that filters to them', function () {
    leadDueYesterday($this->owner);
    issuedInvoice($this->sales);
    Approval::create([
        'approvable_type' => 'quote', 'approvable_id' => 1, 'type' => 'quote',
        'requested_by' => $this->owner->id, 'status' => ApprovalStatus::Pending,
    ]);
    Project::query()->create([
        'code' => 'WI-20261', 'name' => 'Gulberg lounge', 'contact_id' => Contact::factory()->create()->id,
        'business_line' => BusinessLine::Interior, 'stage' => ProjectStage::Design,
        'status' => ProjectStatus::Active, 'value_minor' => 100_000_00,
    ]);
    ScheduledTaskRun::query()->create(['task' => 'woodex:reminders', 'ran_at' => now()->subHour(), 'ok' => false]);

    $response = $this->actingAs($this->owner)->get('/admin')->assertOk();

    foreach (['Enquiries today', 'Follow-ups overdue', 'Awaiting approval', 'Money due', 'Jobs running', 'Automations failing'] as $label) {
        $response->assertSee($label);
    }

    // Every tile is a door, not a dead end: the href carries the same filter the number came from.
    $response->assertSee(route('admin.leads.index', ['created' => 'today']), false)
        ->assertSee(route('admin.leads.index', ['due' => 'overdue']), false)
        ->assertSee(route('admin.approvals'), false)
        ->assertSee(route('admin.payments.index', ['tab' => 'receivables']), false)
        ->assertSee(route('admin.projects.index', ['status' => 'active']), false);
});

it('counts a follow-up as overdue once its next action passes, or its first reply misses the SLA', function () {
    $open = leadDueYesterday($this->owner);
    $sla = Lead::factory()->create([
        'contact_id' => Contact::factory()->create()->id,
        'owner_id' => $this->owner->id,
        'next_action_at' => now()->addDay(),
        'first_response_at' => null,
        'sla_due_at' => now()->subHour(),
    ]);
    // Neither is due: one has a future action and a reply, one has a future action and no SLA promise.
    Lead::factory()->create([
        'contact_id' => Contact::factory()->create()->id,
        'owner_id' => $this->owner->id,
        'next_action_at' => now()->addDay(),
        'first_response_at' => now()->subDay(),
    ]);

    $panel = app(ControlPanel::class);

    expect($panel->overdueFollowUps($this->owner))->toBe(2)
        ->and($open->isOverdue())->toBeTrue()
        ->and($sla->isSlaBreached())->toBeTrue();
});

it('shows the same overdue rows in the list its tile links to', function () {
    $due = leadDueYesterday($this->owner);
    $notDue = Lead::factory()->create([
        'contact_id' => Contact::factory()->create()->id,
        'owner_id' => $this->owner->id,
        'next_action_at' => now()->addDays(2),
        'first_response_at' => now()->subDay(),
    ]);

    $count = app(ControlPanel::class)->overdueFollowUps($this->owner);

    Livewire::actingAs($this->owner)
        ->withQueryParams(['due' => 'overdue'])
        ->test(LeadsIndex::class)
        ->assertSet('due', 'overdue')
        ->assertSee('Follow-ups overdue')
        ->assertSee($due->ref)
        ->assertDontSee($notDue->ref)
        ->assertViewHas('leads', fn ($leads) => $leads->total() === $count);
});

it('filters the list to the enquiries that arrived today', function () {
    $today = Lead::factory()->create([
        'contact_id' => Contact::factory()->create()->id,
        'owner_id' => $this->owner->id,
        'created_at' => now()->subHour(),
    ]);
    $old = Lead::factory()->create([
        'contact_id' => Contact::factory()->create()->id,
        'owner_id' => $this->owner->id,
        'created_at' => now()->subDays(3),
    ]);

    expect(app(ControlPanel::class)->enquiriesToday($this->owner))->toBe(1);

    Livewire::actingAs($this->owner)
        ->withQueryParams(['created' => 'today'])
        ->test(LeadsIndex::class)
        ->assertSet('created', 'today')
        ->assertSee('Enquiries today')
        ->assertSee($today->ref)
        ->assertDontSee($old->ref)
        ->call('clearQuickFilters')
        ->assertSet('created', '')
        ->assertSee($old->ref);
});

it('totals what clients owe and splits out the part that is late', function () {
    issuedInvoice($this->sales);                                                            // Rs 50,000, 3 days late
    issuedInvoice($this->sales, ['due_date' => now()->addDays(5)->toDateString(), 'total_minor' => 2000000, 'balance_minor' => 2000000]); // not yet due
    issuedInvoice($this->sales, ['balance_minor' => 0, 'status' => InvoiceStatus::Paid]);   // settled

    $due = app(ControlPanel::class)->moneyDue();

    expect($due['total_minor'])->toBe(7000000)
        ->and($due['overdue_minor'])->toBe(5000000)
        ->and($due['invoices'])->toBe(2)
        ->and($due['overdue_invoices'])->toBe(1);
});

it('puts late money in front of the owner with the one click that chases it', function () {
    issuedInvoice($this->sales);

    $this->actingAs($this->owner)->get('/admin')
        ->assertOk()
        ->assertSee('is late')
        ->assertSee('Chase it')
        ->assertSee(route('admin.payments.index', ['tab' => 'receivables']), false);
});

it('holds a quotation for a decision and says so on the dashboard', function () {
    Approval::create([
        'approvable_type' => 'quote', 'approvable_id' => 7, 'type' => 'quote',
        'requested_by' => $this->sales->id, 'status' => ApprovalStatus::Pending,
    ]);

    expect(app(ControlPanel::class)->awaitingApproval())->toBe(1);

    $this->actingAs($this->owner)->get('/admin')
        ->assertOk()
        ->assertSee('One quotation is waiting for you')
        ->assertSee('Decide now');
});

it('counts an automation that failed only while its latest run is still a failure', function () {
    ScheduledTaskRun::query()->create(['task' => 'woodex:reminders', 'ran_at' => now()->subHours(3), 'ok' => false]);
    ScheduledTaskRun::query()->create(['task' => 'woodex:reminders', 'ran_at' => now()->subHour(), 'ok' => true]);
    ScheduledTaskRun::query()->create(['task' => 'woodex:daily-digest', 'ran_at' => now()->subHours(2), 'ok' => false]);
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => '{}', 'exception' => 'RuntimeException', 'failed_at' => now(),
    ]);

    $failing = app(ControlPanel::class)->automationsFailing();

    expect($failing['tasks'])->toBe(['woodex:daily-digest'])
        ->and($failing['failed_jobs'])->toBe(1)
        ->and($failing['total'])->toBe(2);

    $this->actingAs($this->owner)->get('/admin')
        ->assertOk()
        ->assertSee('2 automations failed')
        ->assertSee('See what failed');
});

it('names a scheduled task that has stopped reporting, not just one that threw', function () {
    ScheduledTaskRun::query()->create(['task' => 'woodex:reminders', 'ran_at' => now()->subHours(30), 'ok' => true]);
    ScheduledTaskRun::query()->create(['task' => 'woodex:heartbeat', 'ran_at' => now()->subMinutes(2), 'ok' => true]);

    $failing = app(ControlPanel::class)->automationsFailing();

    expect($failing['stale'])->toBe(['woodex:reminders'])
        ->and($failing['tasks'])->toBe([])
        ->and($failing['total'])->toBe(0);

    $this->actingAs($this->owner)->get('/admin')
        ->assertOk()
        ->assertSee('One scheduled task has stopped reporting')
        ->assertSee('woodex:reminders')
        ->assertSee('Check the schedule');
});

it('holds the tracking board column in the URL so a count can link to it', function () {
    leadDueYesterday($this->owner);

    Livewire::actingAs($this->owner)
        ->withQueryParams(['group' => 'uncontacted'])
        ->test(App\Livewire\Admin\Crm\LeadTrackingBoard::class)
        ->assertSet('group', 'uncontacted');

    Livewire::actingAs($this->owner)
        ->withQueryParams(['group' => 'invented'])
        ->test(App\Livewire\Admin\Crm\LeadTrackingBoard::class)
        ->assertSet('group', 'overdue');
});

it('hides the tiles a role cannot open instead of showing dead ends', function () {
    $cards = collect(app(ControlPanel::class)->cards($this->sales))->keyBy('key');

    expect($cards->keys()->all())->toBe(['enquiries_today', 'overdue_followups', 'money_due', 'jobs_running']);

    $accountant = collect(app(ControlPanel::class)->cards($this->accountant))->keyBy('key');

    // No leads permissions: no enquiry or follow-up tile, but the money and the jobs are theirs.
    expect($accountant->has('enquiries_today'))->toBeFalse()
        ->and($accountant->has('money_due'))->toBeTrue()
        ->and($accountant->has('jobs_running'))->toBeTrue();
});

it('shows one person only their own book on the control view', function () {
    leadDueYesterday($this->sales);
    leadDueYesterday($this->owner);
    leadDueYesterday($this->owner);

    expect(app(ControlPanel::class)->overdueFollowUps($this->sales))->toBe(1)
        ->and(app(ControlPanel::class)->overdueFollowUps($this->owner))->toBe(3);
});

it('shows the same numbers above the reports, and a report can be pinned to today', function () {
    leadDueYesterday($this->owner);

    $this->actingAs($this->owner)->get('/admin/reports?tab=funnel&preset=today')
        ->assertOk()
        ->assertSee('Follow-ups overdue')
        ->assertSee('Enquiries today');

    Livewire::actingAs($this->owner)
        ->withQueryParams(['preset' => 'today'])
        ->test(App\Livewire\Admin\Reports\SalesFunnel::class)
        ->assertSet('preset', 'today')
        ->assertSee('Today');
});

it('never prints a tile number it did not query', function () {
    // An empty studio: every number is a real zero, not a placeholder, and the page still renders.
    $body = $this->actingAs($this->owner)->get('/admin')->assertOk()->getContent();

    expect($body)->toContain('Follow-ups overdue')
        ->and($body)->toContain('Nothing is waiting')
        ->and($body)->not->toContain('No estimates yet');
});

it('keeps the dashboard open to an account with none of the tiles', function () {
    $editor = $this->userWithRole('editor');

    expect(app(ControlPanel::class)->cards($editor))->toBe([]);

    $this->actingAs($editor)->get('/admin')
        ->assertOk()
        ->assertSee('the control tiles are hidden rather than shown as dead ends');
});
