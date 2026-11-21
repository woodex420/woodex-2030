<?php

declare(strict_types=1);

use App\Domain\Accounts\Enums\BillStatus;
use App\Domain\Accounts\Models\Bill;
use App\Domain\Accounts\Models\Vendor;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\PipelineStage;
use App\Domain\Projects\Enums\ProjectStage;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\Quote;
use App\Domain\System\Control\ControlPanel;
use App\Domain\System\Dashboard\Board;
use App\Domain\System\Dashboard\DashboardContext;
use App\Domain\System\Dashboard\DrawnWidget;
use App\Domain\System\Dashboard\Figure;
use App\Domain\System\Dashboard\Widget;
use App\Domain\System\Dashboard\WidgetState;
use App\Domain\System\Enums\ApprovalStatus;
use App\Domain\System\Models\Approval;
use App\Domain\System\Models\ScheduledTaskRun;
use App\Domain\System\Telegram\Digests\DailyDigest;
use App\Livewire\Admin\Dashboard;
use App\Models\User;
use App\Support\Reports\ReportRange;
use Carbon\CarbonImmutable;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| The dashboard as widgets (master list T-044; the owner's documents 05 §7 and 07 §1; DL-044)
|--------------------------------------------------------------------------
| What the plan asks, in its order:
|   1. for every widget with a figure, its link opens a list of exactly that many rows; the records are made
|      here, some of them at the edges of the period in Pakistan time;
|   2. every widget in every state; a widget that fails shows its own error and the rest is still drawn;
|   3. as each role, only the widgets that role may see; with a module off, its widget is not in the page;
|   4. the number of database queries the dashboard makes is written down as a limit.
|
| The studio below is made so that a count that is off by one record at an edge is off in the test: for every
| period there is a record in its first second and one in the second before, one in its last second and one in
| the second after; for every age of money one invoice on each of its two edges.
|
| The page is read as a browser reads it: by data-widget, data-widget-state, data-figure and data-list-rows.
*/

beforeEach(function () {
    // A Friday morning in Lahore. The application's clock is Asia/Karachi, and so is every date it keeps.
    $this->travelTo(CarbonImmutable::create(2026, 11, 20, 10, 0, 0, 'Asia/Karachi'));

    $this->owner = $this->userWithRole('owner');
    $this->sales = $this->userWithRole('sales');
});

/** A moment on the studio's clock. */
function onStudioClock(string $moment): CarbonImmutable
{
    return CarbonImmutable::parse($moment, 'Asia/Karachi');
}

/** A lead that is not waiting for anything, made at a given moment. */
function leadMadeAt(string $moment, User $owner, array $attributes = []): Lead
{
    return Lead::factory()->create([
        'contact_id' => Contact::factory()->create()->id,
        'owner_id' => $owner->id,
        'business_line' => BusinessLine::Interior,
        'created_at' => onStudioClock($moment),
        'first_response_at' => onStudioClock($moment)->addMinutes(5),
        'next_action_at' => now()->addDays(2),
        ...$attributes,
    ]);
}

function quoteOf(Lead $lead, User $owner, string $number, QuoteStatus $status, ?string $sent = null, ?string $accepted = null, int $rupees = 100_000): Quote
{
    return Quote::query()->create([
        'number' => $number, 'sequence_key' => 'dashboard', 'lead_id' => $lead->id, 'contact_id' => $lead->contact_id,
        'business_line' => 'interior', 'title' => 'Quotation '.$number, 'status' => $status, 'currency' => 'PKR',
        'subtotal_minor' => $rupees * 100, 'total_minor' => $rupees * 100, 'owner_id' => $owner->id,
        'sent_at' => $sent === null ? null : onStudioClock($sent), 'accepted_at' => $accepted === null ? null : onStudioClock($accepted),
    ]);
}

function invoiceOf(Contact $contact, string $number, InvoiceStatus $status, string $issued, ?string $due, int $balance, ?int $total = null): Invoice
{
    return Invoice::query()->create([
        'number' => $number, 'contact_id' => $contact->id, 'business_line' => BusinessLine::Interior->value, 'status' => $status,
        'issue_date' => $issued, 'due_date' => $due, 'currency' => 'PKR',
        'subtotal_minor' => ($total ?? $balance) * 100, 'total_minor' => ($total ?? $balance) * 100,
        'paid_minor' => (($total ?? $balance) - $balance) * 100, 'balance_minor' => $balance * 100,
    ]);
}

/**
 * A studio with a record on each side of every edge the dashboard counts by. What each figure must come to is
 * written beside the records, and asked of the dashboard in the first test.
 */
function dashboardStudio(User $owner, User $sales): void
{
    $won = PipelineStage::query()->where('key', 'won')->value('id');
    $quotation = PipelineStage::query()->where('key', 'quotation')->value('id');

    // ── Enquiries. The period "this month" is 1 Nov 00:00:00 to 20 Nov 23:59:59; the chart's fourteen days
    //    begin on 7 Nov 00:00:00; "today" begins on 20 Nov 00:00:00.
    leadMadeAt('2026-10-31 23:59:59', $owner);                                        // the second before the month
    $first = leadMadeAt('2026-11-01 00:00:00', $owner, ['stage_id' => $won]);         // its first second
    $own = leadMadeAt('2026-11-10 12:00:00', $sales);                                 // the salesperson's
    leadMadeAt('2026-11-20 23:59:59', $owner);                                        // the last second of the period
    leadMadeAt('2026-11-21 00:00:00', $owner);                                        // the second after it
    leadMadeAt('2026-11-20 00:00:00', $owner);                                        // the first second of today
    leadMadeAt('2026-11-19 23:59:59', $owner);                                        // the second before today
    leadMadeAt('2026-11-05 09:00:00', $owner, ['next_action_at' => now()->subDay(), 'stage_id' => $quotation]);   // a follow-up is overdue
    leadMadeAt('2026-11-06 09:00:00', $sales, ['next_action_at' => now()->subDay()]); // and one of the salesperson's
    leadMadeAt('2026-11-07 00:00:00', $owner);                                        // the first second of the chart
    leadMadeAt('2026-11-06 23:59:59', $owner);                                        // the second before the chart
    leadMadeAt('2026-10-15 09:00:00', $owner, ['next_action_at' => now()->subDay(), 'stage_id' => $won]);         // won: a closed lead is never overdue
    leadMadeAt('2026-11-12 09:00:00', $owner, ['next_action_at' => now()->subDay()])->delete();                   // deleted: in no figure

    // ── Quotations: sent, and accepted, on each side of the period's two ends.
    quoteOf($own, $sales, 'Q-01', QuoteStatus::Sent, sent: '2026-11-01 00:00:00');
    quoteOf($first, $owner, 'Q-02', QuoteStatus::Sent, sent: '2026-10-31 23:59:59');
    quoteOf($first, $owner, 'Q-03', QuoteStatus::Sent, sent: '2026-11-20 23:59:59');
    quoteOf($first, $owner, 'Q-04', QuoteStatus::Sent, sent: '2026-11-21 00:00:00');
    quoteOf($first, $owner, 'Q-05', QuoteStatus::Void, sent: '2026-11-10 10:00:00');                              // sent, then taken back: never counted
    quoteOf($first, $owner, 'Q-06', QuoteStatus::Accepted, sent: '2026-10-20 10:00:00', accepted: '2026-11-01 00:00:00', rupees: 500_000);
    quoteOf($own, $sales, 'Q-07', QuoteStatus::Accepted, sent: '2026-11-12 10:00:00', accepted: '2026-11-20 23:59:59', rupees: 300_000);
    quoteOf($first, $owner, 'Q-08', QuoteStatus::Declined, sent: '2026-11-13 10:00:00', accepted: '2026-11-14 10:00:00'); // accepted once, no longer
    quoteOf($first, $owner, 'Q-09', QuoteStatus::Accepted, sent: '2026-10-25 10:00:00', accepted: '2026-10-31 23:59:59');
    quoteOf($first, $owner, 'Q-10', QuoteStatus::Accepted, sent: '2026-11-15 10:00:00', accepted: '2026-11-21 00:00:00');
    $held = quoteOf($first, $owner, 'Q-11', QuoteStatus::PendingApproval);
    $gone = quoteOf($first, $owner, 'Q-12', QuoteStatus::Draft);

    // ── Approvals: one waits; one waits for a quotation that was deleted; one is decided.
    foreach ([[$held, ApprovalStatus::Pending], [$gone, ApprovalStatus::Pending], [$held, ApprovalStatus::Approved]] as [$quote, $status]) {
        Approval::create(['approvable_type' => 'quote', 'approvable_id' => $quote->id, 'type' => 'quote', 'requested_by' => $sales->id, 'status' => $status]);
    }
    $gone->delete();

    // ── Invoices. Today is 20 Nov: 30 days ago is 21 Oct, 60 is 21 Sep, 90 is 22 Aug. One invoice on each
    //    edge of each age, and by issue date one on each side of the period's two ends.
    $client = Contact::factory()->create();
    invoiceOf($client, 'I-01', InvoiceStatus::Issued, '2026-11-01', '2026-11-20', 100_000);          // due today: not due yet
    invoiceOf($client, 'I-02', InvoiceStatus::Issued, '2026-10-31', '2026-11-19', 50_000);           // one day late
    invoiceOf($client, 'I-03', InvoiceStatus::PartiallyPaid, '2026-11-20', '2026-10-21', 50_000, total: 80_000); // 30 days late
    invoiceOf($client, 'I-04', InvoiceStatus::Issued, '2026-11-21', null, 10_000);                   // no due date: not due yet
    invoiceOf($client, 'I-05', InvoiceStatus::Issued, '2026-09-01', '2026-10-20', 20_000);           // 31 days late
    invoiceOf($client, 'I-06', InvoiceStatus::Issued, '2026-08-01', '2026-09-21', 30_000);           // 60
    invoiceOf($client, 'I-07', InvoiceStatus::Issued, '2026-08-01', '2026-09-20', 40_000);           // 61
    invoiceOf($client, 'I-08', InvoiceStatus::Issued, '2026-07-01', '2026-08-22', 60_000);           // 90
    invoiceOf($client, 'I-09', InvoiceStatus::Issued, '2026-07-01', '2026-08-21', 70_000);           // 91
    invoiceOf($client, 'I-10', InvoiceStatus::Paid, '2026-11-10', '2026-11-12', 0, total: 90_000);   // issued in the period, nothing owed
    invoiceOf($client, 'I-11', InvoiceStatus::Draft, '2026-11-10', '2026-11-01', 15_000);            // a draft is neither issued nor owed
    invoiceOf($client, 'I-12', InvoiceStatus::Void, '2026-11-10', '2026-11-01', 15_000);             // nor is a void one

    // ── Payments, by the day received; one is not verified.
    foreach ([['2026-11-01', 'verified'], ['2026-10-31', 'verified'], ['2026-11-20', 'verified'], ['2026-11-21', 'verified'], ['2026-11-10', 'pending_verification']] as [$day, $status]) {
        Payment::create(['contact_id' => $client->id, 'amount_minor' => 1_000_000, 'received_on' => $day, 'method' => 'cash', 'status' => $status, 'submitted_by' => 'staff']);
    }

    // ── Bills, by the vendor's due date. "Soon" is today to today + 7.
    $vendor = Vendor::query()->create(['name' => 'Timber yard', 'is_active' => true]);
    foreach ([
        ['B-01', BillStatus::Received, '2026-11-19', 0],        // past its due date
        ['B-02', BillStatus::Received, '2026-11-20', 0],        // due today
        ['B-03', BillStatus::PartiallyPaid, '2026-11-27', 5_000], // due in seven days
        ['B-04', BillStatus::Received, '2026-11-28', 0],        // in eight: owed, not soon
        ['B-05', BillStatus::Received, null, 0],                // no due date
        ['B-06', BillStatus::Draft, '2026-11-19', 0],           // not arrived yet
        ['B-07', BillStatus::Paid, '2026-11-19', 20_000],       // settled
    ] as [$number, $status, $due, $paid]) {
        Bill::query()->create([
            'number' => $number, 'vendor_id' => $vendor->id, 'bill_date' => '2026-11-01', 'due_date' => $due, 'status' => $status,
            'subtotal_minor' => 2_000_000, 'tax_minor' => 0, 'total_minor' => 2_000_000, 'paid_minor' => $paid * 100, 'created_by' => $owner->id,
        ]);
    }

    // ── Projects, against their dates; milestones "coming" are due today to today + 14 (4 Dec).
    $project = fn (string $code, ProjectStatus $status, ?string $target) => Project::query()->create([
        'code' => $code, 'name' => 'Project '.$code, 'contact_id' => $client->id, 'business_line' => BusinessLine::Interior,
        'stage' => ProjectStage::Execution, 'status' => $status, 'value_minor' => 100_000_00, 'target_date' => $target,
    ]);
    $milestone = fn (Project $of, string $name, string $due, bool $reached = false) => $of->milestones()->create([
        'name' => $name, 'due_date' => $due, 'reached_at' => $reached ? now()->subDay() : null,
    ]);
    $late = $project('P-1', ProjectStatus::Active, '2026-11-19');            // past the target date
    $onTime = $project('P-2', ProjectStatus::Active, '2026-11-20');          // the target is today: on schedule
    $undated = $project('P-3', ProjectStatus::Active, null);
    $behind = $project('P-4', ProjectStatus::Active, '2026-12-31');
    $both = $project('P-5', ProjectStatus::Active, '2026-11-01');            // late, and a milestone behind: late
    $done = $project('P-6', ProjectStatus::Completed, '2026-11-01');
    $undatedBehind = $project('P-7', ProjectStatus::Active, null);
    $onHold = $project('P-8', ProjectStatus::OnHold, '2026-11-01');            // on hold: not running, so in no figure
    $project('P-9', ProjectStatus::Cancelled, '2026-11-01');
    $milestone($onHold, 'Held: tiles', '2026-11-19');                          // behind, of a project on hold
    $milestone($onHold, 'Held: paint', '2026-11-21');                          // coming, of a project on hold
    $milestone($behind, 'Carcass delivered', '2026-11-19');                  // behind
    $milestone($both, 'Site measured', '2026-11-10');                        // behind
    $milestone($undatedBehind, 'Samples approved', '2026-11-19');            // behind
    $milestone($onTime, 'Polish', '2026-11-20');                             // coming: due today
    $milestone($undated, 'Install', '2026-12-04');                           // coming: in fourteen days
    $milestone($late, 'Handover', '2026-12-05');                             // in fifteen: not coming
    $milestone($behind, 'Design signed', '2026-11-25', reached: true);       // reached
    $milestone($done, 'Snag list', '2026-11-25');                            // of a project that is not active
    $milestone($both, 'Fit-out', '2026-11-22');                              // coming

    // ── Automations: one task failed last, one has been silent for 30 hours, one is well; two failed jobs.
    ScheduledTaskRun::query()->create(['task' => 'woodex:reminders', 'ran_at' => now()->subHour(), 'ok' => false]);
    ScheduledTaskRun::query()->create(['task' => 'woodex:daily-digest', 'ran_at' => now()->subHours(30), 'ok' => true]);
    ScheduledTaskRun::query()->create(['task' => 'woodex:heartbeat', 'ran_at' => now()->subMinute(), 'ok' => true]);
    foreach (range(1, 2) as $job) {
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => "RuntimeException: mail refused\n#0 stack", 'failed_at' => now()]);
    }

    // ── Conversations and the day's record.
    Conversation::factory()->create(['unread_count' => 2]);
    Conversation::factory()->create();
    Conversation::factory()->closed()->create(['unread_count' => 1]);
    Conversation::factory()->create(['status' => ConversationStatus::Pending->value]);
    Activity::create(['lead_id' => $own->id, 'contact_id' => $own->contact_id, 'user_id' => $sales->id, 'type' => 'note', 'body' => 'Called about the kitchen', 'occurred_at' => now()->subHour()]);
    Activity::create(['lead_id' => $first->id, 'contact_id' => $first->contact_id, 'user_id' => $owner->id, 'type' => 'call', 'body' => 'Agreed the advance', 'occurred_at' => now()->subHours(2)]);
}

/**
 * The dashboard as the Board draws it for one person.
 *
 * @return array<string, DrawnWidget> by widget
 */
function drawnFor(User $user, string $preset = 'this_month', ?BusinessLine $line = null): array
{
    $context = new DashboardContext($user, ReportRange::fromPreset($preset), $line, CarbonImmutable::now(), app(ControlPanel::class));

    return collect(app(Board::class)->draw($context))->flatten(1)->keyBy('key')->all();
}

/**
 * Every figure on a person's dashboard, by its name.
 *
 * @return array<string, Figure>
 */
function figuresFor(User $user, string $preset = 'this_month', ?BusinessLine $line = null): array
{
    return collect(drawnFor($user, $preset, $line))->flatMap(fn ($widget) => $widget->figures)->keyBy('key')->all();
}

/** A page as a browser has it. */
function pageOf(TestResponse $response): HTMLDocument
{
    return HTMLDocument::createFromString((string) $response->getContent(), LIBXML_NOERROR);
}

/**
 * How many rows the list at an address says it holds. An address that ends in #name speaks of the list with
 * that id; otherwise the page must hold exactly one list that counts its rows.
 */
function rowsListedAt(string $href, User $as): int
{
    [$address, $fragment] = array_pad(explode('#', $href, 2), 2, null);
    $response = test()->actingAs($as)->get($address);
    expect($response->status())->toBe(200, "{$href} answered {$response->status()}");
    $page = pageOf($response);

    if ($fragment !== null) {
        $list = $page->getElementById($fragment);
        expect($list)->not->toBeNull("{$href}: no list is called {$fragment}");
    } else {
        $lists = $page->querySelectorAll('[data-list-rows]');
        expect($lists->length)->toBe(1, "{$href}: {$lists->length} lists say how many rows they hold");
        $list = $lists->item(0);
    }

    expect($list->hasAttribute('data-list-rows'))->toBeTrue("{$href}: the list does not say how many rows it holds");

    return (int) $list->getAttribute('data-list-rows');
}

/** The widgets drawn in a page, as name → state. */
function widgetsIn(HTMLDocument $page): array
{
    $found = [];
    foreach ($page->querySelectorAll('[data-widget]') as $widget) {
        $found[$widget->getAttribute('data-widget')] = $widget->getAttribute('data-widget-state');
    }

    return $found;
}

function widgetIn(HTMLDocument $page, string $key): ?Element
{
    return $page->querySelector('[data-widget="'.$key.'"]');
}

function wordsOf(?Element $element): string
{
    return trim((string) preg_replace('/\s+/', ' ', (string) $element?->textContent));
}

/** Every widget, by its name, with the module switch as it stands. */
function everyWidget(): array
{
    return collect(app(Board::class)->widgets())->flatten(1)->keyBy(fn (Widget $widget) => $widget->key())->all();
}

/** Puts a widget that cannot be worked out in the place of one of the Board's. */
function breakWidget(Widget $real): void
{
    app()->bind($real::class, fn () => new class($real) extends Widget
    {
        public function __construct(private readonly Widget $real) {}

        public function key(): string
        {
            return $this->real->key();
        }

        public function title(): string
        {
            return $this->real->title();
        }

        public function visibleTo(User $user): bool
        {
            return $this->real->visibleTo($user);
        }

        public function module(): ?string
        {
            return $this->real->module();
        }

        public function followsPeriod(): bool
        {
            return $this->real->followsPeriod();
        }

        public function width(): string
        {
            return $this->real->width();
        }

        public function data(DashboardContext $context): array
        {
            throw new RuntimeException('The table of '.$this->real->key().' cannot be read.');
        }
    });
}

/*
|--------------------------------------------------------------------------
| 1. Every figure opens a list of exactly that many rows
|--------------------------------------------------------------------------
*/

it('counts each figure to the record at the edges of its period, in Pakistan time', function () {
    dashboardStudio($this->owner, $this->sales);

    $counts = array_map(fn (Figure $figure): int => $figure->count, figuresFor($this->owner));

    expect($counts)->toMatchArray([
        // The period, 1 Nov 00:00:00 to 20 Nov 23:59:59.
        'performance.enquiries' => 9, 'performance.accepted' => 2, 'performance.invoiced' => 3, 'performance.collected' => 2,
        'sales_funnel.enquiries' => 9, 'sales_funnel.sent' => 5, 'sales_funnel.accepted' => 2,
        // As it stands now. Today is 20 Nov, from its first second to its last.
        'live_queues.enquiries_today' => 2, 'live_queues.overdue_followups' => 2, 'live_queues.awaiting_approval' => 1,
        'live_queues.money_due' => 9, 'live_queues.jobs_running' => 6, 'live_queues.automations_failing' => 3,
        'needs_attention.overdue_followups' => 2, 'needs_attention.awaiting_approval' => 1, 'needs_attention.money_due' => 7,
        'needs_attention.automations_failing' => 3, 'needs_attention.automations_stale' => 1,
        'money_owed.current' => 2, 'money_owed.d1_30' => 2, 'money_owed.d31_60' => 2, 'money_owed.d61_90' => 2, 'money_owed.d90_plus' => 1,
        'money_owed.open' => 9, 'money_owed.overdue' => 7,
        'payables_due.overdue' => 1, 'payables_due.soon' => 2, 'payables_due.open' => 5,
        'project_health.late' => 2, 'project_health.milestone' => 2, 'project_health.on_time' => 1, 'project_health.undated' => 1, 'project_health.active' => 6,
        'milestones.soon' => 3,
        'new_leads.total' => 5,
        'pipeline.new' => 9, 'pipeline.quotation' => 1, 'pipeline.won' => 2,
        'inbox.unread' => 2, 'inbox.open' => 2,
    ]);

    // The money beside the counts: Rs 800,000 accepted; what is owed adds up over the five ages.
    $figures = figuresFor($this->owner);
    expect($figures['performance.accepted']->amountMinor)->toBe(800_000_00)
        ->and($figures['performance.invoiced']->amountMinor)->toBe(270_000_00)
        ->and($figures['performance.collected']->amountMinor)->toBe(2_000_000)
        ->and($figures['money_owed.open']->amountMinor)->toBe(430_000_00)
        ->and($figures['money_owed.overdue']->amountMinor)->toBe(320_000_00)
        ->and($figures['money_owed.d1_30']->amountMinor)->toBe(100_000_00)
        ->and($figures['payables_due.soon']->amountMinor)->toBe(3_500_000)
        ->and(drawnFor($this->owner)['sales_funnel']->data['rate'])->toBe(40);
});

it('opens, from every figure, a list of exactly that many rows', function () {
    dashboardStudio($this->owner, $this->sales);
    $figures = figuresFor($this->owner);

    // Every figure of the owner's dashboard is a door to its rows: none is left out of the rule.
    expect(array_keys(array_filter($figures, fn (Figure $figure): bool => ! $figure->opens())))->toBe([])
        ->and(count($figures))->toBeGreaterThanOrEqual(45);

    foreach ($figures as $key => $figure) {
        expect(rowsListedAt($figure->href, $this->owner))->toBe($figure->count, "{$key} says {$figure->count} and opens {$figure->href}");
    }
});

it('opens, for a person who sees only their own, a list of their own rows', function () {
    dashboardStudio($this->owner, $this->sales);
    $figures = figuresFor($this->sales);

    // The salesperson has two of the eleven leads and two of the quotations: the figures are theirs, not the studio's.
    expect(array_map(fn (Figure $figure): int => $figure->count, $figures))->toMatchArray([
        'performance.enquiries' => 2,
        'sales_funnel.enquiries' => 2, 'sales_funnel.sent' => 2, 'sales_funnel.accepted' => 1,
        'live_queues.enquiries_today' => 0, 'live_queues.overdue_followups' => 1,
        'new_leads.total' => 1, 'pipeline.new' => 2, 'pipeline.won' => 0,
        // What is not kept by person is the studio's for everybody who may open it.
        'live_queues.money_due' => 9, 'project_health.active' => 6,
    ])->and(array_keys(array_filter($figures, fn (Figure $figure): bool => ! $figure->opens())))->toBe([]);

    foreach ($figures as $key => $figure) {
        expect(rowsListedAt($figure->href, $this->sales))->toBe($figure->count, "{$key} says {$figure->count} and opens {$figure->href}");
    }
});

it('says one number for what waits for a decision: on the tile, beside the menu, in the digest and in the list', function () {
    // The studio holds two pending requests; the quotation of one was deleted. One waits.
    dashboardStudio($this->owner, $this->sales);

    $page = pageOf($this->actingAs($this->owner)->get('/admin')->assertOk());
    $badge = $page->querySelector('#wx-sidebar a[href="'.route('admin.approvals').'"] [data-nav-badge]');

    expect(Approval::query()->where('status', ApprovalStatus::Pending->value)->count())->toBe(2)
        ->and(figuresFor($this->owner)['live_queues.awaiting_approval']->count)->toBe(1)
        ->and(wordsOf($badge))->toBe('1')
        ->and(rowsListedAt(route('admin.approvals'), $this->owner))->toBe(1)
        ->and(app(DailyDigest::class)->build($this->owner))->toContain('Approvals waiting: 1 ');
});

it('lists a month of enquiries from its first minute to its last, on the studio\'s clock', function () {
    // The month was read on the UTC clock: an enquiry of 31 October, 20:00 in Lahore, stood in November.
    foreach (['2026-10-31 23:59:59', '2026-11-01 00:00:00', '2026-11-30 23:59:59', '2026-12-01 00:00:00'] as $moment) {
        leadMadeAt($moment, $this->owner);
    }

    expect(rowsListedAt(route('admin.leads.index', ['month' => '2026-11']), $this->owner))->toBe(2)
        ->and(rowsListedAt(route('admin.leads.index', ['month' => '2026-10']), $this->owner))->toBe(1)
        ->and(rowsListedAt(route('admin.leads.index', ['month' => '2026-12']), $this->owner))->toBe(1)
        // The same month, asked for as a period: the same rows.
        ->and(rowsListedAt(route('admin.leads.index', ['from' => '2026-11-01', 'to' => '2026-11-30']), $this->owner))->toBe(2);
});

it('follows another period and one business line to the same rows', function () {
    dashboardStudio($this->owner, $this->sales);
    // One furniture enquiry, quotation, invoice and payment, so that the line's figures are not all nought.
    $lead = leadMadeAt('2026-11-20 08:00:00', $this->owner, ['business_line' => BusinessLine::Furniture]);
    quoteOf($lead, $this->owner, 'Q-F1', QuoteStatus::Accepted, sent: '2026-11-20 08:30:00', accepted: '2026-11-20 09:00:00')->update(['business_line' => 'furniture']);
    $invoice = invoiceOf($lead->contact, 'I-F1', InvoiceStatus::Issued, '2026-11-20', '2026-11-30', 25_000);
    $invoice->update(['business_line' => 'furniture']);
    $payment = Payment::create(['contact_id' => $lead->contact_id, 'amount_minor' => 500_000, 'received_on' => '2026-11-20', 'method' => 'cash', 'status' => 'verified', 'submitted_by' => 'staff']);
    $payment->allocations()->create(['invoice_id' => $invoice->id, 'amount_minor' => 500_000]);

    foreach ([['today', null], ['last_month', null], ['this_month', BusinessLine::Furniture], ['today', BusinessLine::Furniture]] as [$preset, $line]) {
        $figures = array_filter(figuresFor($this->owner, $preset, $line), fn (Figure $figure): bool => str_starts_with($figure->key, 'performance.') || str_starts_with($figure->key, 'sales_funnel.'));
        expect($figures)->toHaveCount(7);

        foreach ($figures as $key => $figure) {
            expect(rowsListedAt($figure->href, $this->owner))->toBe($figure->count, "{$preset}, ".($line?->value ?? 'all lines').": {$key} says {$figure->count} and opens {$figure->href}");
        }
    }

    // "Today" is one day, 20 Nov: the lead of its first second and of its last are in, the one a second later is not.
    expect(figuresFor($this->owner, 'today')['performance.enquiries']->count)->toBe(3)
        ->and(figuresFor($this->owner, 'last_month')['performance.enquiries']->count)->toBe(2)
        ->and(figuresFor($this->owner, 'this_month', BusinessLine::Furniture)['performance.collected']->count)->toBe(1);
});

it('gives a figure no door where the person may not open the list, and says so', function () {
    dashboardStudio($this->owner, $this->sales);

    // Somebody who may read the finance reports and nothing of the lists behind them.
    $reader = User::factory()->create();
    $reader->givePermissionTo(['dashboard.view', 'reports.view', 'reports.finance']);
    $figures = figuresFor($reader);

    expect(array_keys($figures))->toBe(['performance.accepted', 'performance.invoiced', 'performance.collected'])
        ->and($figures['performance.accepted']->href)->toBeNull()
        ->and($figures['performance.accepted']->count)->toBe(2);

    $page = pageOf($this->actingAs($reader)->get('/admin')->assertOk());
    $figure = $page->querySelector('[data-figure="performance.accepted"]');
    expect($figure->tagName)->toBe('SPAN')
        ->and($figure->getAttribute('data-figure-access'))->toBe('none')
        ->and($figure->hasAttribute('href'))->toBeFalse()
        // The matching report, which this person may open, is still offered.
        ->and(wordsOf(widgetIn($page, 'performance')))->toContain('Open matching report');

    // The line under the figures speaks of the figures this person is shown, and of no other.
    $reads = wordsOf(widgetIn($page, 'performance')->querySelector('[data-widget-definition]'));
    expect($reads)->toContain('amounts in PKR', 'Accepted:', 'Issued:', 'Collected:')->not->toContain('Enquiries:');
    $sees = wordsOf(widgetIn(pageOf($this->actingAs($this->sales)->get('/admin')->assertOk()), 'performance')->querySelector('[data-widget-definition]'));
    expect($sees)->toContain('Enquiries: your own')->not->toContain('PKR', 'Accepted:', 'Issued:', 'Collected:');

    // A sales manager may open the Automation screen, which lists every task and not the failing ones: the
    // tile is shown, and it is not called a list of its rows.
    $manager = $this->userWithRole('sales_manager');
    $closed = array_keys(array_filter(figuresFor($manager), fn (Figure $figure): bool => ! $figure->opens()));
    expect($closed)->toBe(['live_queues.automations_failing', 'needs_attention.automations_failing', 'needs_attention.automations_stale']);
});

it('prints every figure with its count and its door', function () {
    dashboardStudio($this->owner, $this->sales);
    $page = pageOf($this->actingAs($this->owner)->get('/admin')->assertOk());

    foreach (figuresFor($this->owner) as $key => $figure) {
        $printed = $page->querySelector('[data-figure="'.$key.'"]');
        expect($printed)->not->toBeNull("{$key} is not in the page")
            ->and((int) $printed->getAttribute('data-figure-count'))->toBe($figure->count, $key)
            ->and($printed->getAttribute('href'))->toBe($figure->href, $key)
            ->and($printed->hasAttribute('data-figure-rows'))->toBeTrue($key);
    }

    // And nothing in the page calls itself a figure that the Board did not count.
    expect($page->querySelectorAll('[data-figure]')->length)->toBe(count(figuresFor($this->owner)));
});

/*
|--------------------------------------------------------------------------
| 2. Every widget in every state
|--------------------------------------------------------------------------
*/

it('draws every widget ready when there is something to show, and every chart says its dates, its currency and what it counts', function () {
    dashboardStudio($this->owner, $this->sales);
    $page = pageOf($this->actingAs($this->owner)->get('/admin')->assertOk());

    expect(widgetsIn($page))->toBe(array_fill_keys([
        'performance', 'sales_funnel', 'live_queues', 'needs_attention', 'go_live_checklist', 'money_owed', 'payables_due',
        'project_health', 'milestones', 'new_leads', 'pipeline', 'recent_activity', 'inbox',
    ], 'ready'));

    // What each chart is about: its dates, its currency (or that it counts no money) and its definition.
    foreach ([
        'performance' => ['1 Nov – 20 Nov 2026', 'amounts in PKR', 'drafts and void ones left out'],
        'sales_funnel' => ['1 Nov – 20 Nov 2026', 'value in PKR', 'quotations accepted ÷ quotations sent'],
        'money_owed' => ['20 Nov 2026', 'amounts in PKR', 'by days past its due date'],
        'payables_due' => ['20 Nov 2026', 'amounts in PKR', 'by the vendor\'s due date'],
        'project_health' => ['20 Nov 2026', 'no money', 'Each active project is counted once'],
        'milestones' => ['20 Nov – 4 Dec 2026', 'no money', 'not reached and fall due in these days'],
        'new_leads' => ['7 Nov – 20 Nov 2026', 'no money', 'counted on the day it was made'],
        'pipeline' => ['over all dates', 'no money', 'in the stage it stands in'],
        'inbox' => ['As it stands now', 'no money', 'whatever its status'],
    ] as $key => $says) {
        $definition = wordsOf(widgetIn($page, $key)->querySelector('[data-widget-definition]'));
        foreach ($says as $words) {
            expect($definition)->toContain($words);
        }
    }

    // Each widget is a named region of the page.
    foreach ($page->querySelectorAll('[data-widget]') as $widget) {
        $heading = $page->getElementById($widget->getAttribute('aria-labelledby'));
        expect($heading?->tagName)->toBe('H2')->and(wordsOf($heading))->not->toBe('');
    }

    expect(wordsOf(widgetIn($page, 'sales_funnel')->querySelector('[data-funnel-rate]')))->toBe('40%')
        ->and(wordsOf(widgetIn($page, 'milestones')))->toContain('Polish', 'Fit-out', 'Install', 'today', 'in 2 days', 'in 14 days')
        ->not->toContain('Handover', 'Snag list', 'Design signed')
        ->and(wordsOf(widgetIn($page, 'recent_activity')))->toContain('Called about the kitchen', 'Agreed the advance');
});

it('draws a widget with nothing to count as empty, in words, and keeps what the chart is about', function () {
    $page = pageOf($this->actingAs($this->owner)->get('/admin')->assertOk());

    // Three are never empty: the period's figures and the queues are real noughts, and the checklist is a list of things to do.
    expect(widgetsIn($page))->toBe([
        'performance' => 'ready', 'sales_funnel' => 'empty', 'live_queues' => 'ready', 'needs_attention' => 'empty', 'go_live_checklist' => 'ready',
        'money_owed' => 'empty', 'payables_due' => 'empty', 'project_health' => 'empty', 'milestones' => 'empty',
        'new_leads' => 'empty', 'pipeline' => 'empty', 'recent_activity' => 'empty', 'inbox' => 'empty',
    ]);

    foreach ([
        'sales_funnel' => 'No enquiry was made and no quotation was sent or accepted in this period (1 Nov – 20 Nov 2026).',
        'needs_attention' => 'Nothing is waiting',
        'money_owed' => 'Nothing is owed: no issued invoice has a balance.',
        'payables_due' => 'Nothing is owed to a vendor',
        'project_health' => 'No project is active yet.',
        'milestones' => 'No milestone of an active project falls due in the next 14 days (20 Nov – 4 Dec 2026).',
        'new_leads' => 'No enquiry was made in these 14 days (7 Nov – 20 Nov 2026).',
        'pipeline' => 'No lead yet.',
        'recent_activity' => 'No activity yet',
        'inbox' => 'No conversation is open and nothing is unread.',
    ] as $key => $words) {
        $empty = widgetIn($page, $key)->querySelector('[data-widget-empty]');
        expect($empty?->getAttribute('role'))->toBe('status', $key)->and(wordsOf($empty))->toContain($words);
        // An empty widget prints no figure (no row of noughts) and no line about a chart that is not there:
        // a widget that counts over dates names them in its sentence, above.
        expect(widgetIn($page, $key)->querySelectorAll('[data-figure]')->length)->toBe(0, $key)
            ->and(widgetIn($page, $key)->querySelector('[data-widget-definition]'))->toBeNull($key);
    }

    // A nought that is a figure opens a list that says nought.
    $figures = figuresFor($this->owner);
    foreach (['live_queues.overdue_followups', 'live_queues.money_due', 'live_queues.automations_failing', 'performance.invoiced', 'money_owed.open', 'project_health.late', 'payables_due.overdue', 'inbox.unread'] as $key) {
        expect($figures[$key]->count)->toBe(0)->and(rowsListedAt($figures[$key]->href, $this->owner))->toBe(0, $key);
    }

    // The funnel with enquiries and no quotation sent: drawn, and it says why there is no rate.
    leadMadeAt('2026-11-10 10:00:00', $this->owner);
    $funnel = widgetIn(pageOf($this->actingAs($this->owner)->get('/admin')->assertOk()), 'sales_funnel');
    expect($funnel->getAttribute('data-widget-state'))->toBe('ready')
        ->and(wordsOf($funnel->querySelector('[data-funnel-rate]')))->toBe('—')
        ->and(wordsOf($funnel->querySelector('[data-widget-definition]')))->toContain('1 Nov – 20 Nov 2026', 'none was sent, so there is no rate');
});

it('shows the error of a widget that fails in that widget alone, and draws the rest', function (string $broken) {
    dashboardStudio($this->owner, $this->sales);
    Exceptions::fake();
    breakWidget(everyWidget()[$broken]);

    $response = $this->actingAs($this->owner)->get('/admin')->assertOk();
    $page = pageOf($response);
    $states = widgetsIn($page);

    expect($states[$broken])->toBe('error')
        ->and(array_unique(array_values(array_diff_key($states, [$broken => true]))))->toBe(['ready'])
        ->and(count($states))->toBe(13);

    $widget = widgetIn($page, $broken);
    $alert = $widget->querySelector('[data-widget-error]');
    expect($alert->getAttribute('role'))->toBe('alert')
        ->and(wordsOf($alert))->toContain('could not be loaded', 'Nothing was changed', 'the rest of this page is not affected', 'Try again')
        // What failed is for the log, not for the page.
        ->not->toContain('cannot be read', 'RuntimeException')
        // The number to give when reporting it is the number of this answer.
        ->and(wordsOf($alert->querySelector('[data-request-number]')))->toBe($response->headers->get('X-Request-Id'))
        ->and($alert->querySelector('button[wire\:click="refresh"]'))->not->toBeNull()
        ->and($widget->querySelectorAll('[data-figure]')->length)->toBe(0);

    Exceptions::assertReported(fn (RuntimeException $failure): bool => $failure->getMessage() === "The table of {$broken} cannot be read.");
})->with([
    'performance', 'sales_funnel', 'live_queues', 'needs_attention', 'go_live_checklist', 'money_owed', 'payables_due',
    'project_health', 'milestones', 'new_leads', 'pipeline', 'recent_activity', 'inbox',
]);

it('draws the rest when a table a widget reads is not there at all', function () {
    dashboardStudio($this->owner, $this->sales);
    Exceptions::fake();
    // Not a stand-in: the real widgets, and a database that has lost one table.
    DB::statement('alter table project_milestones rename to project_milestones_gone');

    try {
        $states = widgetsIn(pageOf($this->actingAs($this->owner)->get('/admin')->assertOk()));
    } finally {
        DB::statement('alter table project_milestones_gone rename to project_milestones');
    }

    expect($states['project_health'])->toBe('error')
        ->and($states['milestones'])->toBe('error')
        ->and(array_unique(array_values(array_diff_key($states, ['project_health' => true, 'milestones' => true]))))->toBe(['ready']);
})->skip(fn () => DB::connection()->getDriverName() !== 'sqlite', 'Renaming a table ends the transaction a test runs in on MySQL and MariaDB; the stand-in above covers every widget there.');

it('says "no access" in a widget whose right was taken away while the page stood open, and leaves it out of the next page', function () {
    dashboardStudio($this->owner, $this->sales);

    $page = Livewire::actingAs($this->owner)->test(Dashboard::class);
    expect(widgetsIn(HTMLDocument::createFromString($page->html(), LIBXML_NOERROR)))->toHaveCount(13);

    // The owner is made an accountant while the page is open.
    $this->owner->syncRoles(['accountant']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->owner->unsetRelations();

    $after = HTMLDocument::createFromString($page->call('refresh')->html(), LIBXML_NOERROR);
    $states = widgetsIn($after);

    // What an accountant may see is drawn as before; what they may not is said, not dropped and not kept.
    expect($states)->toBe([
        'performance' => 'ready', 'sales_funnel' => 'no-access', 'live_queues' => 'ready', 'needs_attention' => 'ready', 'go_live_checklist' => 'no-access',
        'money_owed' => 'ready', 'payables_due' => 'ready', 'project_health' => 'ready', 'milestones' => 'ready',
        'new_leads' => 'no-access', 'pipeline' => 'no-access', 'recent_activity' => 'no-access', 'inbox' => 'no-access',
    ]);

    foreach (['sales_funnel', 'go_live_checklist', 'new_leads', 'pipeline', 'recent_activity', 'inbox'] as $key) {
        $widget = widgetIn($after, $key);
        $told = $widget->querySelector('[data-widget-no-access]');
        expect($told->getAttribute('role'))->toBe('status')
            ->and(wordsOf($told))->toContain('You no longer have access to', 'Your role was changed while this page was open')
            // No figure of it is left on the page.
            ->and($widget->querySelectorAll('[data-figure]')->length)->toBe(0, $key)
            ->and($widget->querySelector('[data-widget-definition]'))->toBeNull();
    }
    expect(wordsOf(widgetIn($after, 'recent_activity')))->not->toContain('Called about the kitchen');

    // Opened again, the page is an accountant's: those six are not in it.
    expect(array_keys(widgetsIn(pageOf($this->actingAs($this->owner)->get('/admin')->assertOk()))))
        ->toBe(['performance', 'live_queues', 'needs_attention', 'money_owed', 'payables_due', 'project_health', 'milestones']);
});

it('cannot be told by the browser which widgets were on the page', function () {
    Livewire::actingAs($this->userWithRole('support'))->test(Dashboard::class)
        ->assertSet('shown', ['performance', 'live_queues', 'needs_attention', 'new_leads', 'pipeline', 'recent_activity', 'inbox'])
        ->set('shown', ['money_owed', 'payables_due']);
})->throws(Exception::class, 'Cannot update locked property: [shown]');

it('carries, in every widget, the layer that says it is loading, for the fields the widget waits for', function () {
    dashboardStudio($this->owner, $this->sales);
    $page = pageOf($this->actingAs($this->owner)->get('/admin')->assertOk());
    $widgets = everyWidget();

    foreach ($page->querySelectorAll('[data-widget]') as $drawn) {
        $key = $drawn->getAttribute('data-widget');
        $layer = $drawn->querySelector('[data-widget-loading]');
        $waitsFor = array_map(trim(...), explode(',', (string) $layer->getAttribute('wire:target')));

        expect($layer->getAttribute('role'))->toBe('status', $key)
            ->and(wordsOf($layer))->toBe('Loading '.Str::lcfirst($widgets[$key]->title()).'…')
            // Shown by Livewire while it asks, hidden otherwise; a quick answer does not flash it.
            ->and($layer->hasAttribute('wire:loading.flex.delay'))->toBeTrue($key)
            ->and(explode(' ', (string) $layer->getAttribute('class')))->toContain('hidden')
            // Every widget waits for "Try again"; only one that follows the period waits for the period.
            ->and($waitsFor)->toContain('refresh')
            ->and(in_array('preset', $waitsFor, true))->toBe($widgets[$key]->followsPeriod(), $key)
            ->and($drawn->getAttribute('wire:target'))->toBe($layer->getAttribute('wire:target'))
            ->and($drawn->hasAttribute('wire:loading.attr'))->toBeTrue();
    }

    expect(array_keys(array_filter($widgets, fn (Widget $widget): bool => $widget->followsPeriod())))->toBe(['performance', 'sales_funnel']);

    // The minute's own refresh names no field, so it shows no layer.
    expect($page->querySelector('[wire\:poll\.60s\.visible]'))->not->toBeNull();
});

it('refuses a state that is not one of the widget\'s', function () {
    expect(array_map(fn (WidgetState $state): string => $state->value, WidgetState::cases()))->toBe(['ready', 'empty', 'error', 'no-access'])
        ->and(WidgetState::tryFrom('loading'))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| 3. Each role sees only its own widgets; a module that is off takes its widget with it
|--------------------------------------------------------------------------
*/

it('shows each role only the widgets that role may see', function (string $role, array $widgets) {
    dashboardStudio($this->owner, $this->sales);
    $person = $this->userWithRole($role);
    $page = pageOf($this->actingAs($person)->get('/admin')->assertOk());

    expect(array_keys(widgetsIn($page)))->toBe($widgets)
        ->and(app(Board::class)->keysFor($person))->toBe($widgets);

    // The period is offered only to somebody who has a widget that follows it.
    expect($page->querySelector('[aria-label="Performance period"]') !== null)->toBe(in_array('performance', $widgets, true));
})->with([
    'owner' => ['owner', ['performance', 'sales_funnel', 'live_queues', 'needs_attention', 'go_live_checklist', 'money_owed', 'payables_due', 'project_health', 'milestones', 'new_leads', 'pipeline', 'recent_activity', 'inbox']],
    'admin' => ['admin', ['performance', 'sales_funnel', 'live_queues', 'needs_attention', 'go_live_checklist', 'money_owed', 'payables_due', 'project_health', 'milestones', 'new_leads', 'pipeline', 'recent_activity', 'inbox']],
    'sales manager' => ['sales_manager', ['performance', 'sales_funnel', 'live_queues', 'needs_attention', 'money_owed', 'project_health', 'milestones', 'new_leads', 'pipeline', 'recent_activity', 'inbox']],
    'sales' => ['sales', ['performance', 'sales_funnel', 'live_queues', 'needs_attention', 'money_owed', 'project_health', 'milestones', 'new_leads', 'pipeline', 'recent_activity', 'inbox']],
    'designer' => ['designer_pm', ['performance', 'sales_funnel', 'live_queues', 'needs_attention', 'project_health', 'milestones', 'new_leads', 'pipeline', 'recent_activity', 'inbox']],
    'accountant' => ['accountant', ['performance', 'live_queues', 'needs_attention', 'money_owed', 'payables_due', 'project_health', 'milestones']],
    'support' => ['support', ['performance', 'live_queues', 'needs_attention', 'new_leads', 'pipeline', 'recent_activity', 'inbox']],
]);

it('tells a person with none of the widgets so, in one box, and offers no period', function () {
    $page = pageOf($this->actingAs($this->userWithRole('editor'))->get('/admin')->assertOk());

    expect(widgetsIn($page))->toBe(['dashboard' => 'no-access'])
        ->and(wordsOf(widgetIn($page, 'dashboard')->querySelector('[data-widget-no-access]')))
        ->toContain('None of the dashboard\'s figures are open to your role', 'the control tiles are hidden rather than shown as dead ends')
        ->and($page->querySelector('[aria-label="Performance period"]'))->toBeNull()
        ->and($page->querySelectorAll('[data-figure]')->length)->toBe(0);
});

it('leaves a widget out of the page while its module is switched off', function () {
    dashboardStudio($this->owner, $this->sales);

    // As the Furniture installation starts: the Inbox module is off.
    config(['modules.enabled' => 'lead_api']);
    $page = pageOf($this->actingAs($this->owner)->get('/admin')->assertOk());
    expect(array_keys(widgetsIn($page)))->not->toContain('inbox')
        ->and(widgetsIn($page))->toHaveCount(12)
        ->and(wordsOf($page->querySelector('main') ?? $page->body))->not->toContain('What waits in the inbox now')
        ->and(array_keys(everyWidget()))->not->toContain('inbox')
        // Absent, not "no access": nobody is told about a module that is not here.
        ->and($page->querySelector('[data-widget-state="no-access"]'))->toBeNull();

    // Also on a page that was open when the module was switched off.
    config(['modules.enabled' => '*']);
    $open = Livewire::actingAs($this->owner)->test(Dashboard::class);
    expect(widgetsIn(HTMLDocument::createFromString($open->html(), LIBXML_NOERROR)))->toHaveKey('inbox');
    config(['modules.enabled' => 'lead_api']);
    expect(widgetsIn(HTMLDocument::createFromString($open->call('refresh')->html(), LIBXML_NOERROR)))->not->toHaveKey('inbox');

    // Every widget that names a module names one the installation knows, and every door of the page is open.
    config(['modules.enabled' => '*']);
    foreach (everyWidget() as $key => $widget) {
        expect($widget->module() === null || in_array($widget->module(), array_keys(config('modules.available')), true))->toBeTrue($key);
    }
});

/*
|--------------------------------------------------------------------------
| 4. The number of queries, as a limit
|--------------------------------------------------------------------------
*/

it('asks the database no more than its limit, however many records there are', function () {
    dashboardStudio($this->owner, $this->sales);

    $queries = function (User $as): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($as)->test(Dashboard::class);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    // The limit: what the owner's dashboard, which holds every widget, asks today. Five of them are the
    // framework's (the roles, the rights and the settings); the rest are the thirteen widgets'. A new widget
    // may raise the number, on purpose and in this line; a query for every row may not (below).
    $limit = 52;

    $owner = $queries($this->owner);
    expect($owner)->toBeLessThanOrEqual($limit, "The owner's dashboard made {$owner} queries; the limit is {$limit}");

    // Nobody's dashboard asks more than the owner's, and a person with no widget asks for nothing but who they are.
    foreach (['admin', 'sales_manager', 'sales', 'designer_pm', 'accountant', 'support'] as $role) {
        $made = $queries($this->userWithRole($role));
        expect($made)->toBeLessThanOrEqual($owner, "{$role}: {$made} queries")->toBeGreaterThan(5);
    }
    expect($queries($this->userWithRole('editor')))->toBeLessThanOrEqual(5);

    // Twenty more of everything a widget lists: not one query more than the same page asked before them.
    // (Drawn again in one process, the framework remembers the roles and the settings, so the page is
    // measured a second time first: like is compared with like.)
    $before = $queries($this->owner);
    foreach (range(1, 20) as $n) {
        $lead = leadMadeAt('2026-11-15 10:00:00', $this->owner, ['next_action_at' => now()->subDay()]);
        invoiceOf($lead->contact, 'I-MORE-'.$n, InvoiceStatus::Issued, '2026-11-15', '2026-11-16', 5_000);
        Activity::create(['lead_id' => $lead->id, 'contact_id' => $lead->contact_id, 'user_id' => $this->sales->id, 'type' => 'note', 'body' => 'More '.$n, 'occurred_at' => now()]);
        Project::query()->where('code', 'P-2')->firstOrFail()->milestones()->create(['name' => 'More '.$n, 'due_date' => '2026-11-21']);
        Conversation::factory()->create(['unread_count' => 1]);
    }

    expect($queries($this->owner))->toBe($before);
});
