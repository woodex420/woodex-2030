<?php

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Projects\Actions\OpenProjectFromQuote;
use App\Domain\Projects\Actions\RaiseMilestoneInvoice;
use App\Domain\Projects\Models\Project;
use App\Domain\Sales\Actions\AcceptQuote;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\RaiseAdvanceInvoice;
use App\Domain\Sales\Actions\ReviseQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Enums\DiscountType;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Exceptions\QuoteActionDenied;
use App\Domain\Sales\Mail\QuoteSentMail;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\QuoteEvent;
use App\Domain\Sales\Models\QuoteVersion;
use App\Domain\Sales\Notifications\QuoteNotification;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuoteLinks;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\QueryException;
use Illuminate\Mail\MailManager;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Queue\Connectors\DatabaseConnector;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 24)->setTime(11, 0));
    Storage::fake('local');
    Mail::fake();
    Notification::fake();
    $this->owner = $this->userWithRole('owner');
    $this->sales = $this->userWithRole('sales');
    $this->pm = $this->userWithRole('designer_pm');
});

function safetyDraft(): Quote
{
    $lead = Lead::factory()->create(['owner_id' => test()->sales->id, 'business_line' => 'interior']);
    $quote = app(CreateQuote::class)->handle($lead->contact, BusinessLine::Interior, test()->sales, $lead, null, 'Safety fixture');
    $editor = app(QuoteEditor::class);
    $section = $quote->sections()->first() ?? $editor->addSection($quote, 'Work');
    $editor->addLine($section, ['description' => 'Test work', 'unit' => 'job', 'qty' => '1', 'rate_minor' => 20_000_000]);
    $quote->items()->where('rate_minor', 0)->each(fn ($item) => $editor->deleteLine($item));

    return $quote->refresh();
}

function safetyAccepted(): Quote
{
    $quote = safetyDraft();
    app(SendQuote::class)->handle($quote, test()->sales);
    app(AcceptQuote::class)->handle($quote, 'Test client', null, null, null);

    return $quote->refresh();
}

it('rejects a stale duplicate send without a new version email event or changed PDF', function () {
    $quote = safetyDraft();
    $quote->contact->update(['email' => 'client@example.test']);
    $stale = $quote->fresh();
    $first = app(SendQuote::class)->handle($quote, $this->sales, email: true);
    $bytes = Storage::disk('local')->get($first->pdf_path);

    expect(fn () => app(SendQuote::class)->handle($stale, $this->sales, email: true))->toThrow(QuoteActionDenied::class, 'Revise');
    expect($quote->versions()->count())->toBe(1)
        ->and(QuoteEvent::where('quote_id', $quote->id)->where('type', 'sent')->count())->toBe(1)
        ->and(Storage::disk('local')->get($first->pdf_path))->toBe($bytes)
        ->and(hash('sha256', $bytes))->toBe($first->pdf_sha256);
    Mail::assertQueued(QuoteSentMail::class, 1);
});

it('rechecks approval against current figures rather than the passed model', function () {
    $quote = safetyDraft();
    $stale = $quote->fresh();
    app(QuoteEditor::class)->updateDetails($quote, ['discount_type' => DiscountType::Percent, 'discount_value' => 800]);

    expect(fn () => app(SendQuote::class)->handle($stale, $this->sales))->toThrow(QuoteActionDenied::class);
    expect($quote->versions()->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('rechecks current validity instead of stale validity', function () {
    $quote = safetyDraft();
    $stale = $quote->fresh();
    $quote->update(['valid_until' => today()->subDay()]);

    expect(fn () => app(SendQuote::class)->handle($stale, $this->sales))->toThrow(ValidationException::class);
    expect($quote->versions()->count())->toBe(0);
});

it('uses fresh relations when freezing a quotation', function () {
    $quote = safetyDraft()->load(['contact', 'sections.items', 'milestones']);
    $quote->contact()->update(['name' => 'Current client', 'email' => 'current@example.test']);
    $version = app(SendQuote::class)->handle($quote, $this->sales, email: true);

    expect($version->snapshot['client']['name'])->toBe('Current client');
    Mail::assertQueued(QuoteSentMail::class, fn ($mail) => $mail->hasTo('current@example.test'));
});

it('rechecks send actor activity and permissions', function (bool $disable) {
    $quote = safetyDraft();
    $staleActor = $this->sales->load('roles', 'permissions');
    if ($disable) {
        $this->sales->update(['is_active' => false]);
    } else {
        $this->sales->syncRoles([]);
    }

    expect(fn () => app(SendQuote::class)->handle($quote, $staleActor))->toThrow($disable ? HttpException::class : AuthorizationException::class);
    expect($quote->versions()->count())->toBe(0);
})->with([true, false]);

it('does not send a newly prepared revision through a stale model', function () {
    $quote = safetyDraft();
    $staleDraft = $quote->fresh();
    app(SendQuote::class)->handle($quote, $this->sales);
    app(ReviseQuote::class)->handle($quote, $this->sales);

    expect(fn () => app(SendQuote::class)->handle($staleDraft, $this->sales))->toThrow(QuoteActionDenied::class, 'changed');
    expect($quote->versions()->count())->toBe(1)->and($quote->fresh()->status)->toBe(QuoteStatus::Draft);
});

it('accepts at most once through stale models', function () {
    $quote = safetyDraft();
    app(SendQuote::class)->handle($quote, $this->sales);
    $stale = $quote->fresh();
    app(AcceptQuote::class)->handle($quote, 'First client', null, null, null);

    expect(fn () => app(AcceptQuote::class)->handle($stale, 'Second client', null, null, null))->toThrow(QuoteActionDenied::class);
    expect($quote->fresh()->accepted_name)->toBe('First client')
        ->and(Project::count())->toBe(1)->and(Invoice::count())->toBe(1)
        ->and(QuoteEvent::where('quote_id', $quote->id)->where('type', 'accepted')->count())->toBe(1);
    Notification::assertSentToTimes($this->owner, QuoteNotification::class, 1);
});

it('rechecks the persisted acceptability of a stale sent quotation', function (QuoteStatus $status) {
    $quote = safetyDraft();
    app(SendQuote::class)->handle($quote, $this->sales);
    $stale = $quote->fresh();
    $quote->update(['status' => $status]);

    expect(fn () => app(AcceptQuote::class)->handle($stale, 'Test client', null, null, null))->toThrow(QuoteActionDenied::class);
    expect(Project::count())->toBe(0)->and(Invoice::count())->toBe(0);
})->with([QuoteStatus::Draft, QuoteStatus::ChangesRequested, QuoteStatus::Declined, QuoteStatus::Expired, QuoteStatus::Void]);

it('rechecks persisted expiry on acceptance', function () {
    $quote = safetyDraft();
    app(SendQuote::class)->handle($quote, $this->sales);
    $stale = $quote->fresh();
    $quote->update(['valid_until' => today()->subDay()]);
    expect(fn () => app(AcceptQuote::class)->handle($stale, 'Test client', null, null, null))->toThrow(QuoteActionDenied::class, 'expired');
    expect(Project::count())->toBe(0)->and(Invoice::count())->toBe(0);
});

it('binds acceptance forms to the displayed revision and refuses an old form after resend', function () {
    $quote = safetyDraft();
    app(SendQuote::class)->handle($quote, $this->sales);
    $page = QuoteLinks::signed('quotes.portal', $quote);
    $this->get($page)->assertOk()->assertSee('name="revision" value="0"', false);
    app(ReviseQuote::class)->handle($quote->refresh(), $this->sales);
    app(SendQuote::class)->handle($quote, $this->sales);
    $url = QuoteLinks::signed('quotes.portal.accept', $quote);
    $this->post($url, ['revision' => 0, 'name' => 'Old form client', 'confirm' => '1'])->assertSessionHasErrors('name', errorBag: 'accept');
    expect(Project::count())->toBe(0)->and(Invoice::count())->toBe(0);
    $this->post($url, ['revision' => 1, 'name' => 'Current form client', 'confirm' => '1'])->assertSessionHasNoErrors();
    expect($quote->fresh()->accepted_name)->toBe('Current form client')->and(Invoice::count())->toBe(1);
});

it('requires an explicit displayed revision on a public acceptance', function () {
    $quote = safetyDraft();
    app(SendQuote::class)->handle($quote, $this->sales);
    $this->post(QuoteLinks::signed('quotes.portal.accept', $quote), ['name' => 'Test client', 'confirm' => '1'])
        ->assertSessionHasErrors('revision', errorBag: 'accept');
    expect(Invoice::count())->toBe(0);
});

it('does not open projects or advances from unaccepted quotes', function () {
    $quote = safetyDraft();
    app(SendQuote::class)->handle($quote, $this->sales);
    expect(fn () => app(OpenProjectFromQuote::class)->handle($quote))->toThrow(QuoteActionDenied::class);
    expect(fn () => app(RaiseAdvanceInvoice::class)->handle($quote))->toThrow(QuoteActionDenied::class);
    expect(Project::count())->toBe(0)->and(Invoice::count())->toBe(0);
});

it('does not reopen an archived project through a repeated acceptance helper', function () {
    $quote = safetyAccepted();
    $quote->project->delete();
    expect(fn () => app(OpenProjectFromQuote::class)->handle($quote))->toThrow(QuoteActionDenied::class, 'archived');
    expect(Project::withTrashed()->count())->toBe(1);
});

it('returns the same advance and never re-freezes an existing issued PDF', function () {
    $quote = safetyAccepted();
    $first = Invoice::sole();
    $snapshot = $first->snapshot;
    $bytes = Storage::disk('local')->get($first->pdf_path);
    $quote->contact->update(['name' => 'Changed after issue']);
    $again = app(RaiseAdvanceInvoice::class)->handle($quote);
    $frozen = app(RaiseAdvanceInvoice::class)->freeze($first);
    expect($again->id)->toBe($first->id)->and($frozen->snapshot)->toBe($snapshot)
        ->and(Storage::disk('local')->get($first->pdf_path))->toBe($bytes)->and(Invoice::count())->toBe(1);
});

it('fails closed rather than replacing a missing frozen invoice PDF', function () {
    safetyAccepted();
    $invoice = Invoice::sole();
    Storage::disk('local')->delete($invoice->pdf_path);
    expect(fn () => app(RaiseAdvanceInvoice::class)->freeze($invoice))->toThrow(RuntimeException::class, 'reviewed');
    expect($invoice->fresh()->pdf_sha256)->toBe($invoice->pdf_sha256);
    Storage::disk('local')->assertMissing($invoice->pdf_path);
});

it('raises a milestone once with fresh amounts and always as a draft', function () {
    $quote = safetyAccepted();
    $milestone = $quote->project->milestones()->orderBy('position')->get()[1];
    $stale = $milestone->fresh();
    $milestone->update(['amount_minor' => 7_000_000]);
    $first = app(RaiseMilestoneInvoice::class)->handle($stale, $this->pm);
    $again = app(RaiseMilestoneInvoice::class)->handle($stale, $this->pm);
    expect($first->id)->toBe($again->id)->and($first->total_minor)->toBe(7_000_000)
        ->and($first->status)->toBe(InvoiceStatus::Draft)->and($first->snapshot)->toBeNull()
        ->and($first->issue_date)->toBeNull()->and(Invoice::count())->toBe(2);
    Mail::assertNothingQueued();
});

it('refuses the automatic issue escape hatch for milestone invoices', function () {
    $quote = safetyAccepted();
    $milestone = $quote->project->milestones()->orderBy('position')->get()[1];
    expect(fn () => app(RaiseMilestoneInvoice::class)->handle($milestone, $this->pm, issue: true))->toThrow(ValidationException::class);
    expect(Invoice::count())->toBe(1);
});

it('rechecks milestone invoicing actor activity and permissions', function (bool $disable) {
    $quote = safetyAccepted();
    $milestone = $quote->project->milestones()->orderBy('position')->get()[1];
    $staleActor = $this->pm->load('roles', 'permissions');
    if ($disable) {
        $this->pm->update(['is_active' => false]);
    } else {
        $this->pm->syncRoles([]);
    }
    expect(fn () => app(RaiseMilestoneInvoice::class)->handle($milestone, $staleActor))->toThrow($disable ? HttpException::class : AuthorizationException::class);
    expect(Invoice::count())->toBe(1);
})->with([true, false]);

it('reserves archived milestone invoices for Accounts review instead of rebilling', function () {
    $quote = safetyAccepted();
    $first = Invoice::sole();
    $first->delete();
    expect(fn () => app(RaiseAdvanceInvoice::class)->handle($quote))->toThrow(QuoteActionDenied::class, 'archived');
    expect(fn () => app(RaiseMilestoneInvoice::class)->handle($quote->project->milestones()->first(), $this->pm))->toThrow(ValidationException::class);
    expect(Invoice::withTrashed()->count())->toBe(1);
});

it('allocates the next invoice suffix above historical gaps and archived invoices', function () {
    $quote = safetyAccepted();
    $first = Invoice::sole();
    $first->update(['number' => $quote->number.'-9']);
    $first->delete();
    $milestone = $quote->project->milestones()->orderBy('position')->get()[1];
    $next = app(RaiseMilestoneInvoice::class)->handle($milestone, $this->pm);
    expect($next->number)->toBe($quote->number.'-10');
});

it('enforces one invoice per milestone at the database boundary including archived records', function () {
    safetyAccepted();
    $first = Invoice::sole();
    $first->delete();
    $duplicate = $first->replicate(['public_id', 'number', 'deleted_at']);
    $duplicate->number = 'TEST-DUPLICATE';
    expect(fn () => $duplicate->save())->toThrow(QueryException::class);
    expect(Invoice::withTrashed()->count())->toBe(1);
});

it('rolls back sending when PDF storage fails', function () {
    $quote = safetyDraft();
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->once()->andReturnFalse();
    Storage::shouldReceive('disk')->with('local')->andReturn($disk);
    expect(fn () => app(SendQuote::class)->handle($quote, $this->sales, email: true))->toThrow(RuntimeException::class, 'stored');
    expect($quote->fresh()->status)->toBe(QuoteStatus::Draft)->and(QuoteVersion::count())->toBe(0);
    Mail::assertNothingQueued();
});

it('rolls back acceptance project advance and audit if advance PDF storage fails', function () {
    $quote = safetyDraft();
    app(SendQuote::class)->handle($quote, $this->sales);
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->once()->andReturnFalse();
    $disk->shouldReceive('put')->once()->andReturnFalse();
    $disk->shouldReceive('delete')->once()->andReturnTrue();
    Storage::shouldReceive('disk')->with('local')->andReturn($disk);
    expect(fn () => app(AcceptQuote::class)->handle($quote, 'Test client', null, null, null))->toThrow(RuntimeException::class, 'stored');
    expect($quote->fresh()->status)->toBe(QuoteStatus::Sent)->and(Project::count())->toBe(0)->and(Invoice::count())->toBe(0)
        ->and(QuoteEvent::where('quote_id', $quote->id)->where('type', 'accepted')->count())->toBe(0)
        ->and($quote->contact->fresh()->is_client)->toBeFalse();
    Notification::assertNothingSent();
});

it('preserves a frozen sent PDF when the next revision fails after PDF rendering', function () {
    $quote = safetyDraft();
    $first = app(SendQuote::class)->handle($quote, $this->sales);
    $bytes = Storage::disk('local')->get($first->pdf_path);
    app(ReviseQuote::class)->handle($quote, $this->sales);
    Event::listen('eloquent.creating: '.QuoteVersion::class, static function (): void {
        throw new RuntimeException('Simulated insert failure');
    });
    expect(fn () => app(SendQuote::class)->handle($quote, $this->sales, email: true))->toThrow(RuntimeException::class, 'insert');
    expect($quote->fresh()->status)->toBe(QuoteStatus::Draft)->and(QuoteVersion::count())->toBe(1)
        ->and(Storage::disk('local')->get($first->pdf_path))->toBe($bytes)
        ->and(hash('sha256', $bytes))->toBe($first->pdf_sha256);
    Mail::assertNothingQueued();
});

it('marks quotation mail and staff notifications for dispatch after the outermost commit', function () {
    $quote = safetyDraft();
    expect((new QuoteSentMail($quote->id, 1))->afterCommit)->toBeTrue()
        ->and((new QuoteNotification($quote, 'accepted'))->afterCommit)->toBeTrue();
});

it('does not admit quotation delivery jobs before the outer transaction commits', function (bool $rollBack) {
    $manager = new QueueManager(app());
    $manager->addConnector('database', fn () => new DatabaseConnector(app('db')));
    Queue::swap($manager);
    config(['queue.default' => 'database', 'mail.default' => 'array']);
    Mail::swap(new MailManager(app()));
    Notification::swap(new ChannelManager(app()));
    $quote = safetyDraft();
    $quote->contact->update(['email' => 'client@example.test']);

    try {
        DB::transaction(function () use ($quote, $rollBack): void {
            app(SendQuote::class)->handle($quote, $this->sales, email: true);
            app(AcceptQuote::class)->handle($quote, 'Test client', null, null, null);
            expect(DB::table('jobs')->count())->toBe(0);
            if ($rollBack) {
                throw new RuntimeException('Test outer rollback');
            }
        });
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('Test outer rollback');
    }

    if ($rollBack) {
        expect(DB::table('jobs')->count())->toBe(0)->and(Project::count())->toBe(0)
            ->and(Invoice::count())->toBe(0)->and(QuoteVersion::count())->toBe(0)
            ->and($quote->fresh()->status)->toBe(QuoteStatus::Draft);
    } else {
        expect(DB::table('jobs')->pluck('payload')->implode(' '))->toContain('SendQueuedMailable')->toContain('SendQueuedNotifications');
        expect(Project::count())->toBe(1)->and(Invoice::count())->toBe(1)->and(QuoteVersion::count())->toBe(1);
    }
})->with([true, false]);

it('refuses to silently deduplicate financial history when adding the milestone index', function () {
    safetyAccepted();
    $migration = require database_path('migrations/2026_10_17_030000_unique_invoice_project_milestone.php');
    $migration->down();
    $milestoneKeys = array_values(array_filter(DB::connection()->getSchemaBuilder()->getForeignKeys('invoices'),
        fn (array $key): bool => $key['columns'] === ['project_milestone_id']
    ));
    expect($milestoneKeys)->toHaveCount(1)
        ->and($milestoneKeys[0]['foreign_table'])->toBe('project_milestones');
    $duplicate = Invoice::sole()->replicate(['public_id', 'number']);
    $duplicate->number = 'TEST-EXISTING-DUPLICATE';
    $duplicate->save();
    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Accounts review');
    expect(Invoice::count())->toBe(2);
    // Only this in-memory test fixture is removed; the production migration never removes records.
    $duplicate->forceDelete();
    $migration->up();
});


it('uses a grouped milestone projection compatible with strict MySQL for the migration guard', function () {
    $migration = require database_path('migrations/2026_10_17_030000_unique_invoice_project_milestone.php');
    // Compile/log only: do not rerun DDL or alter financial fixtures in this regression check.
    $queries = DB::pretend(fn () => $migration->up());
    $guards = array_values(array_filter($queries, fn (array $query): bool =>
        str_contains(strtolower($query['query']), 'having count(*) > 1')
    ));

    expect($guards)->toHaveCount(1);
    expect(strtolower($guards[0]['query']))
        ->toMatch('/select [`"]project_milestone_id[`"] from [`"]invoices[`"] /')
        ->not->toContain('select *');
});


it('keeps the payment method account reference type identical to the chart primary key', function () {
    $schema = DB::connection()->getSchemaBuilder();

    expect($schema->getColumnType('payment_method_accounts', 'account_id', fullDefinition: true))
        ->toBe($schema->getColumnType('chart_of_accounts', 'id', fullDefinition: true));

    $references = array_values(array_filter($schema->getForeignKeys('payment_method_accounts'),
        fn (array $key): bool => $key['columns'] === ['account_id']
    ));
    expect($references)->toHaveCount(1)
        ->and($references[0]['foreign_table'])->toBe('chart_of_accounts')
        ->and($references[0]['foreign_columns'])->toBe(['id']);
});


it('keeps estimate relationship key types and foreign keys compatible with their parent', function (string $table, string $column, string $parent) {
    $schema = DB::connection()->getSchemaBuilder();
    expect($schema->getColumnType($table, $column, fullDefinition: true))
        ->toBe($schema->getColumnType($parent, 'id', fullDefinition: true));

    $references = array_values(array_filter($schema->getForeignKeys($table),
        fn (array $key): bool => $key['columns'] === [$column]
    ));
    expect($references)->toHaveCount(1)
        ->and($references[0]['foreign_table'])->toBe($parent)
        ->and($references[0]['foreign_columns'])->toBe(['id']);
})->with([
    ['estimates', 'contact_id', 'contacts'],
    ['estimates', 'lead_id', 'leads'],
    ['estimates', 'quote_id', 'quotes'],
    ['estimates', 'created_by', 'users'],
    ['estimates', 'reviewed_by', 'users'],
    ['quotes', 'estimate_id', 'estimates'],
]);
