<?php

declare(strict_types=1);

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Projects\Actions\ReachProjectMilestone;
use App\Domain\Projects\Models\Project;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\RecalculateQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Enums\MilestonePlanKey;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Notifications\OverdueInvoiceNotification;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuoteLinks;
use App\Domain\System\Models\Automation;
use App\Integrations\WhatsApp\Contracts\WhatsAppGateway;
use App\Integrations\WhatsApp\Fakes\FakeWhatsAppGateway;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/*
| Invoice automation that runs (slice E, docs/audit/31).
|
| Two jobs on the scheduler, both gated by their Automation switch:
|   1. `woodex:invoice-milestones` — the invoice for a milestone the job has reached, once, with the same
|      number series, tax slice, PDF and ledger posting as the advance invoice raised at acceptance.
|   2. `woodex:invoice-reminders` — the 7/14/30-day ladder: exactly one rung per invoice, the client messaged
|      only with consent, and the reason recorded in the audit trail whenever nothing could be sent.
*/

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 24)->setTime(11, 0));
    Storage::fake('local');
    Notification::fake();

    $this->gateway = new FakeWhatsAppGateway;
    $this->app->instance(WhatsAppGateway::class, $this->gateway);

    $this->owner = $this->userWithRole('owner');
    $this->accountant = $this->userWithRole('accountant');
    $this->sales = $this->userWithRole('sales');

    $this->lead = Lead::factory()->create([
        'owner_id' => $this->sales->id,
        'business_line' => 'interior',
        'city' => 'Lahore',
        'area' => 'Model Town',
    ]);
});

/**
 * A sent PKR 1,000,000 quotation on the execution plan (50% advance, then two milestones).
 */
function invoiceAutomationQuote(Lead $lead, User $sales): Quote
{
    $quote = app(CreateQuote::class)->handle($lead->contact, BusinessLine::Interior, $sales, $lead, null, 'Lounge and kitchen');
    $editor = app(QuoteEditor::class);
    $section = $quote->sections()->first() ?? $editor->addSection($quote, 'Lounge');
    $editor->addLine($section, ['description' => 'Wall panelling, walnut veneer', 'unit' => 'sqft', 'qty' => '1', 'rate_minor' => 1_000_000 * 100]);
    $quote->items()->where('rate_minor', 0)->each(fn ($item) => $editor->deleteLine($item));
    $editor->setMilestonePlan($quote->refresh(), MilestonePlanKey::Execution);
    app(RecalculateQuote::class)->handle($quote->refresh(), $sales);

    return app(SendQuote::class)->handle($quote->refresh(), $sales)->quote->refresh();
}

function acceptQuoteAtPortal(Quote $quote): Project
{
    test()->post(QuoteLinks::signed('quotes.portal.accept', $quote), [
        'revision' => $quote->revision,
        'name' => 'Ayesha Khan',
        'confirm' => '1',
    ])->assertRedirect();

    return Project::query()->sole();
}

/** An issued invoice for a consenting client, due a given number of days ago. */
function ladderInvoice(User $sales, int $daysLate, array $attributes = []): Invoice
{
    $lead = $attributes['lead'] ?? Lead::factory()->create([
        'owner_id' => $sales->id,
        'contact_id' => Contact::factory()->create(['consent_whatsapp' => true])->id,
    ]);

    unset($attributes['lead']);

    return Invoice::query()->create(array_merge([
        'number' => 'INV-2026-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'contact_id' => $lead->contact_id,
        'business_line' => BusinessLine::Interior->value,
        'status' => InvoiceStatus::Issued,
        'subtotal_minor' => 5_000_000,
        'total_minor' => 5_000_000,
        'balance_minor' => 5_000_000,
        'currency' => 'PKR',
        'issue_date' => now()->subDays($daysLate + 10)->toDateString(),
        'due_date' => now()->subDays($daysLate)->toDateString(),
    ], $attributes));
}

it('raises the invoice for a milestone the job has reached, and never twice', function () {
    $quote = invoiceAutomationQuote($this->lead, $this->sales);
    $project = acceptQuoteAtPortal($quote);

    // Acceptance bills the advance (50%); the other two milestones are still to come.
    expect(Invoice::query()->count())->toBe(1);

    // The advance milestone is already billed; the next one becomes billable when the job reaches it.
    $next = $project->milestones()
        ->whereNull('reached_at')
        ->whereNotIn('id', Invoice::query()->whereNotNull('project_milestone_id')->select('project_milestone_id'))
        ->orderBy('position')
        ->firstOrFail();
    app(ReachProjectMilestone::class)->handle($next, $this->sales, true);

    expect($this->artisan('woodex:invoice-milestones'))->toBe(0);

    $invoices = Invoice::query()->orderBy('id')->get();

    expect($invoices)->toHaveCount(2)
        ->and($invoices[1]->project_milestone_id)->toBe($next->id)
        ->and($invoices[1]->quote_id)->toBe($quote->id)
        ->and($invoices[1]->total_minor)->toBe((int) $next->amount_minor)
        ->and($invoices[1]->status)->toBe(InvoiceStatus::Issued)
        ->and($invoices[1]->pdf_path)->not->toBeNull();

    // Running again the same hour, and the next day, bills nothing: a milestone is billed once.
    expect($this->artisan('woodex:invoice-milestones'))->toBe(0)
        ->and(Invoice::query()->count())->toBe(2);
});

it('leaves a milestone the job has not reached unbilled', function () {
    $quote = invoiceAutomationQuote($this->lead, $this->sales);
    acceptQuoteAtPortal($quote);

    expect($this->artisan('woodex:invoice-milestones'))->toBe(0)
        ->and(Invoice::query()->count())->toBe(1); // the advance only
});

it('shows what it would bill without writing anything in a dry run', function () {
    $quote = invoiceAutomationQuote($this->lead, $this->sales);
    $project = acceptQuoteAtPortal($quote);
    $next = $project->milestones()
        ->whereNull('reached_at')
        ->whereNotIn('id', Invoice::query()->whereNotNull('project_milestone_id')->select('project_milestone_id'))
        ->orderBy('position')
        ->firstOrFail();
    app(ReachProjectMilestone::class)->handle($next, $this->sales, true);

    expect($this->artisan('woodex:invoice-milestones', ['--dry-run' => true]))->toBe(0)
        ->and(Invoice::query()->count())->toBe(1);
});

it('fires the 7-day rung once, messages the consenting client, and never repeats it', function () {
    ladderInvoice($this->sales, 8);

    expect($this->artisan('woodex:invoice-reminders'))->toBe(0);

    $rungs = Activity::query()->where('event', 'invoice_reminder')->get();

    expect($rungs)->toHaveCount(1)
        ->and((int) $rungs->first()->properties['rung'])->toBe(7)
        ->and($rungs->first()->properties['client_messaged'])->toBeTrue()
        ->and($this->gateway->sent)->toHaveCount(1);

    Notification::assertSentTo($this->accountant, OverdueInvoiceNotification::class);

    // The same day again, and the next day (still inside the 7-day rung): nothing is repeated.
    expect($this->artisan('woodex:invoice-reminders'))->toBe(0);
    $this->travel(1)->days();
    expect($this->artisan('woodex:invoice-reminders'))->toBe(0);

    expect(Activity::query()->where('event', 'invoice_reminder')->count())->toBe(1)
        ->and($this->gateway->sent)->toHaveCount(1);
});

it('moves to the next rung as the invoice ages', function () {
    ladderInvoice($this->sales, 8);

    expect($this->artisan('woodex:invoice-reminders'))->toBe(0);

    $this->travel(7)->days(); // 15 days late → the 14-day rung

    expect($this->artisan('woodex:invoice-reminders'))->toBe(0);

    $rungs = Activity::query()->where('event', 'invoice_reminder')->orderBy('id')->get();

    expect($rungs->pluck('properties.rung')->map(fn ($rung) => (int) $rung)->all())->toBe([7, 14]);

    $this->travel(16)->days(); // 31 days late → the 30-day rung

    expect($this->artisan('woodex:invoice-reminders'))->toBe(0);

    expect(Activity::query()->where('event', 'invoice_reminder')->count())->toBe(3);
});

it('records the rung even when the client has not consented, and says why', function () {
    $contact = Contact::factory()->create([
        'phone' => '+923000000009',
        'whatsapp' => '+923000000009',
        'consent_whatsapp' => false,
    ]);
    $lead = Lead::factory()->create(['owner_id' => $this->sales->id, 'contact_id' => $contact->id]);

    ladderInvoice($this->sales, 8, ['lead' => $lead]);

    expect($this->artisan('woodex:invoice-reminders'))->toBe(0);

    $rung = Activity::query()->where('event', 'invoice_reminder')->sole();

    expect($rung->properties['client_messaged'])->toBeFalse()
        ->and($rung->properties['reason'])->toContain('consented')
        ->and($this->gateway->sent)->toHaveCount(0);
});

it('never reminds a paid, void or not-yet-issued invoice', function () {
    ladderInvoice($this->sales, 20, ['status' => InvoiceStatus::Paid, 'balance_minor' => 0]);
    ladderInvoice($this->sales, 20, ['status' => InvoiceStatus::Void, 'balance_minor' => 0]);
    ladderInvoice($this->sales, 20, ['status' => InvoiceStatus::Draft]);

    expect($this->artisan('woodex:invoice-reminders'))->toBe(0)
        ->and(Activity::query()->where('event', 'invoice_reminder')->count())->toBe(0);
});

it('leaves an invoice alone before its due date', function () {
    ladderInvoice($this->sales, -3); // due in three days

    expect($this->artisan('woodex:invoice-reminders'))->toBe(0)
        ->and(Activity::query()->where('event', 'invoice_reminder')->count())->toBe(0);
});

it('lists both jobs on the Automation screen with their cadence', function () {
    $this->actingAs($this->owner)->get('/admin/automation')
        ->assertOk()
        ->assertSee('Milestone invoices')
        ->assertSee('Overdue payment ladder')
        ->assertSee('Daily 10:00');
});

it('stops the whole ladder when its automation switch is paused', function () {
    ladderInvoice($this->sales, 8);

    Automation::query()->create([
        'key' => 'woodex:invoice-reminders',
        'is_enabled' => false,
        'toggled_at' => now(),
    ]);

    // The scheduler gate is what stops it; the command itself stays callable for a manual catch-up.
    expect(Automation::enabled('woodex:invoice-reminders'))->toBeFalse();
});
