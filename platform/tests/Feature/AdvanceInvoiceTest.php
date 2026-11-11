<?php

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Projects\Models\Project;
use App\Domain\Sales\Actions\AcceptQuote;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\RaiseAdvanceInvoice;
use App\Domain\Sales\Actions\RecalculateQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Enums\MilestonePlanKey;
use App\Domain\Sales\Models\BankAccount;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuoteLinks;
use App\Models\User;
use App\Settings\SalesSettings;

/*
| Acceptance automation (QUO-011, INV-001/004, PRJ-001): the moment a client accepts, the project that carries the
| work is opened and the advance invoice is raised — 50% on an execution job, 75% on a design job — numbered from
| the quotation, payable into the bank account of the business line, and frozen as a PDF when it is issued.
*/
beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 24)->setTime(11, 0));
    Storage::fake('local');
    Notification::fake();

    $this->sales = $this->userWithRole('sales');
    $this->lead = Lead::factory()->create(['owner_id' => $this->sales->id, 'business_line' => 'interior', 'city' => 'Lahore', 'area' => 'Model Town']);

    // Interior → "WOODEX INTERIOR"; Furniture has its own account.
    BankAccount::query()->create(['business_line' => BusinessLine::Interior, 'title' => 'WOODEX INTERIOR', 'bank_name' => 'Bank Alfalah', 'account_number' => '0000-0000-0000', 'iban' => 'PK00ALFA0000000000000000', 'is_default' => true, 'is_active' => true]);
    BankAccount::query()->create(['business_line' => BusinessLine::Furniture, 'title' => 'WOODEX FURNITURE', 'bank_name' => 'Meezan Bank', 'account_number' => '1111-1111-1111', 'iban' => 'PK11MEZN0000000000000000', 'is_default' => true, 'is_active' => true]);
});

/**
 * A sent quotation of PKR 1,000,000 (minor: ×100) with a payment plan, ready to be accepted.
 *
 * @param  ?callable(Quote): void  $shape  last edits before it is sent, while it is still editable
 */
function sentQuote(Lead $lead, User $sales, MilestonePlanKey $plan = MilestonePlanKey::Execution, int $rupees = 1_000_000, ?callable $shape = null): Quote
{
    $quote = app(CreateQuote::class)->handle($lead->contact, BusinessLine::Interior, $sales, $lead, null, 'Lounge and kitchen');
    $editor = app(QuoteEditor::class);
    $section = $quote->sections()->first() ?? $editor->addSection($quote, 'Lounge');
    $editor->addLine($section, ['description' => 'Wall panelling, walnut veneer', 'unit' => 'sqft', 'qty' => '1', 'rate_minor' => $rupees * 100]);
    $quote->items()->where('rate_minor', 0)->each(fn ($item) => $editor->deleteLine($item));
    $editor->setMilestonePlan($quote->refresh(), $plan);

    if ($shape !== null) {
        $shape($quote->refresh());
    }

    app(RecalculateQuote::class)->handle($quote->refresh(), $sales);

    return app(SendQuote::class)->handle($quote->refresh(), $sales)->quote->refresh();
}

it('opens the project and raises the advance invoice when the client accepts', function () {
    $quote = sentQuote($this->lead, $this->sales);

    $this->post(QuoteLinks::signed('quotes.portal.accept', $quote), ['revision' => $quote->revision, 'name' => 'Ayesha Khan', 'confirm' => '1'])->assertRedirect();

    $project = Project::query()->sole();

    expect($project->code)->toBe($quote->number)
        ->and($project->contact_id)->toBe($quote->contact_id)
        ->and($project->business_line)->toBe(BusinessLine::Interior)
        ->and($project->value_minor)->toBe((int) $quote->total_minor)
        ->and($project->city)->toBe('Lahore')
        ->and($project->start_date->toDateString())->toBe('2026-11-24')
        ->and($project->milestones)->toHaveCount(3);

    $invoice = Invoice::query()->sole();

    expect($invoice->number)->toBe($quote->number.'-1')
        ->and($invoice->status)->toBe(InvoiceStatus::Issued) // the default: issue, but do not send
        ->and((int) $invoice->total_minor)->toBe(intdiv((int) $quote->total_minor, 2)) // 50% of 1,000,000
        ->and((int) $invoice->balance_minor)->toBe((int) $invoice->total_minor)
        ->and($invoice->bankAccount?->title)->toBe('WOODEX INTERIOR')
        ->and($invoice->project_id)->toBe($project->id)
        ->and($invoice->due_date->toDateString())->toBe('2026-12-01') // seven days
        ->and($invoice->items)->toHaveCount(1)
        ->and($invoice->items->first()->description)->toContain('Advance with work order');

    // The invoice is frozen: a PDF and a snapshot are stored with it.
    expect($invoice->pdf_path)->not->toBeNull()
        ->and(Storage::disk('local')->exists((string) $invoice->pdf_path))->toBeTrue()
        ->and($invoice->snapshot['number'])->toBe($invoice->number);

    // The milestone of the project and the invoice point at each other.
    expect($invoice->projectMilestone->quote_milestone_id)->toBe($quote->milestones->first()->id);

    $this->assertDatabaseHas('quote_events', ['quote_id' => $quote->id, 'type' => 'invoiced']);
});

it('raises 75% on a design quotation and 100% on a custom one', function () {
    $design = sentQuote($this->lead, $this->sales, MilestonePlanKey::Design);
    $this->post(QuoteLinks::signed('quotes.portal.accept', $design), ['revision' => $design->revision, 'name' => 'Ayesha Khan', 'confirm' => '1']);

    expect((int) Invoice::query()->where('quote_id', $design->id)->sole()->total_minor)
        ->toBe((int) round((int) $design->total_minor * 0.75));

    // A custom plan of one stage, payable in full with the work order.
    $lead = Lead::factory()->create(['owner_id' => $this->sales->id, 'business_line' => 'interior']);
    $custom = sentQuote($lead, $this->sales, MilestonePlanKey::Execution, 1_000_000, function (Quote $quote): void {
        $editor = app(QuoteEditor::class);
        $editor->updateMilestone($quote->milestones->first(), ['name' => 'Full payment in advance', 'percent_bp' => 10000]);

        foreach ($quote->milestones->skip(1) as $milestone) {
            $editor->deleteMilestone($milestone);
        }
    });

    $this->post(QuoteLinks::signed('quotes.portal.accept', $custom), ['revision' => $custom->revision, 'name' => 'Bilal Ahmed', 'confirm' => '1']);

    expect((int) Invoice::query()->where('quote_id', $custom->id)->sole()->total_minor)
        ->toBe((int) $custom->total_minor)
        ->and($custom->milestonePlan())->toBe(MilestonePlanKey::Custom);
});

it('never opens a second project or a second advance invoice', function () {
    $quote = sentQuote($this->lead, $this->sales);
    $this->post(QuoteLinks::signed('quotes.portal.accept', $quote), ['revision' => $quote->revision, 'name' => 'Ayesha Khan', 'confirm' => '1']);

    // Accepting again is refused by the portal; raising again by hand changes nothing either.
    $this->post(QuoteLinks::signed('quotes.portal.accept', $quote), ['revision' => $quote->revision, 'name' => 'Someone else', 'confirm' => '1']);
    $again = app(RaiseAdvanceInvoice::class)->handle($quote->refresh(), $this->sales);

    expect(Project::query()->count())->toBe(1)
        ->and(Invoice::query()->count())->toBe(1)
        ->and($again->id)->toBe(Invoice::query()->sole()->id);
});

it('leaves the advance as a draft when the owner asks for that', function () {
    app(SalesSettings::class)->fill(['advance_invoice_mode' => 'draft'])->save();

    $quote = sentQuote($this->lead, $this->sales);
    $this->post(QuoteLinks::signed('quotes.portal.accept', $quote), ['revision' => $quote->revision, 'name' => 'Ayesha Khan', 'confirm' => '1']);

    $invoice = Invoice::query()->sole();

    expect($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->issue_date)->toBeNull()
        ->and($invoice->due_date)->toBeNull()
        ->and($invoice->pdf_path)->toBeNull();
});

it('preserves legacy furniture milestone billing into the account of its business line', function () {
    $lead = Lead::factory()->create(['owner_id' => $this->sales->id, 'business_line' => 'furniture']);
    $quote = app(CreateQuote::class)->handle($lead->contact, BusinessLine::Furniture, $this->sales, $lead, null, 'Wardrobe');
    $editor = app(QuoteEditor::class);
    $quote->update(['meta' => [...$quote->meta, 'furniture_billing' => 'milestone']]); // Historical furniture contracts retain milestone billing.
    $section = $quote->sections()->first() ?? $editor->addSection($quote, 'Wardrobe');
    $editor->addLine($section, ['description' => 'Hinged wardrobe, matte laminate', 'unit' => 'sqft', 'qty' => '1', 'rate_minor' => 300_000_00]);
    $quote->items()->where('rate_minor', 0)->each(fn ($item) => $editor->deleteLine($item));
    app(RecalculateQuote::class)->handle($quote->refresh(), $this->sales);
    $quote = app(SendQuote::class)->handle($quote->refresh(), $this->sales)->quote->refresh();

    $this->post(QuoteLinks::signed('quotes.portal.accept', $quote), ['revision' => $quote->revision, 'name' => 'Ayesha Khan', 'confirm' => '1']);

    expect(Invoice::query()->sole()->bankAccount?->title)->toBe('WOODEX FURNITURE');
});

it('lists the invoices and serves the PDF to the team', function () {
    $quote = sentQuote($this->lead, $this->sales);
    $this->post(QuoteLinks::signed('quotes.portal.accept', $quote), ['revision' => $quote->revision, 'name' => 'Ayesha Khan', 'confirm' => '1']);
    $invoice = Invoice::query()->sole();

    $accountant = $this->userWithRole('accountant');

    $this->actingAs($accountant)->get(route('admin.invoices.index'))
        ->assertOk()
        ->assertSee($invoice->number)
        ->assertSee($quote->contact->name);

    $this->actingAs($accountant)->get(route('admin.invoices.pdf', $invoice))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    // A user without the permission sees nothing.
    $this->actingAs($this->userWithRole('designer_pm'))->get(route('admin.invoices.index'))->assertForbidden();
});

it('shows the project and the invoice on the quotation page', function () {
    $quote = sentQuote($this->lead, $this->sales);
    $this->post(QuoteLinks::signed('quotes.portal.accept', $quote), ['revision' => $quote->revision, 'name' => 'Ayesha Khan', 'confirm' => '1']);
    $invoice = Invoice::query()->sole();

    $this->actingAs($this->userWithRole('owner'))->get(route('admin.quotes.show', $quote))
        ->assertOk()
        ->assertSee('Project and invoices')
        ->assertSee($quote->number) // the project code
        ->assertSee($invoice->number);
});

it('keeps the tax rate of the quotation on the advance', function () {
    $quote = sentQuote($this->lead, $this->sales, shape: function (Quote $quote): void {
        $quote->forceFill(['tax_bp' => 1600])->save(); // 16% sales tax, before freezing the sent quote
        app(RecalculateQuote::class)->handle($quote->refresh(), $this->sales);
    });
    app(AcceptQuote::class)->handle($quote, 'Test client', null, null, null);
    $invoice = app(RaiseAdvanceInvoice::class)->handle($quote->refresh(), $this->sales);

    expect($invoice->items->first()->tax_bp)->toBe(1600)
        ->and((int) $invoice->subtotal_minor + (int) $invoice->tax_minor)->toBe((int) $invoice->total_minor)
        ->and((int) $invoice->total_minor)->toBe(intdiv((int) $quote->total_minor, 2));
});
