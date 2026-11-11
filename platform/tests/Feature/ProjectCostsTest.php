<?php

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectExpense;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\RecalculateQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Enums\MilestonePlanKey;
use App\Domain\Sales\Models\BankAccount;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuoteLinks;
use App\Livewire\Admin\Projects\ProjectShow;
use App\Models\User;
use Livewire\Livewire;

/*
| P9 (PRJ-009): cost tracking per project — what the work actually cost, against
| what the quote's internal cost said it would, so margin reporting is a look
| and not a spreadsheet archaeology dig.
*/

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 24)->setTime(11, 0));

    $this->sales = $this->userWithRole('sales');
    $this->pm = $this->userWithRole('designer_pm');
    $this->lead = Lead::factory()->create(['owner_id' => $this->sales->id, 'business_line' => 'interior', 'city' => 'Lahore', 'area' => 'Model Town']);

    BankAccount::query()->create(['business_line' => BusinessLine::Interior, 'title' => 'WOODEX INTERIOR', 'bank_name' => 'Bank Alfalah', 'account_number' => '0000-0000-0000', 'iban' => 'PK00ALFA0000000000000000', 'is_default' => true, 'is_active' => true]);

    $this->quote = costsProjectQuote($this->lead, $this->sales);
    $this->project = Project::query()->sole();
});

/** A sent, accepted quotation worth PKR 1,000,000 on the 50/40/10 plan, and the project it opened. */
function costsProjectQuote(Lead $lead, User $sales): Quote
{
    $quote = app(CreateQuote::class)->handle($lead->contact, BusinessLine::Interior, $sales, $lead, null, 'Lounge and kitchen');
    $editor = app(QuoteEditor::class);
    $section = $quote->sections()->first() ?? $editor->addSection($quote, 'Lounge');
    $editor->addLine($section, ['description' => 'Wall panelling, walnut veneer', 'unit' => 'sqft', 'qty' => '1', 'rate_minor' => 1_000_000 * 100]);
    $quote->items()->where('rate_minor', 0)->each(fn ($item) => $editor->deleteLine($item));
    $editor->setMilestonePlan($quote->refresh(), MilestonePlanKey::Execution);
    app(RecalculateQuote::class)->handle($quote->refresh(), $sales);

    $sent = app(SendQuote::class)->handle($quote->refresh(), $sales)->quote->refresh();

    test()->post(QuoteLinks::signed('quotes.portal.accept', $sent), ['revision' => $sent->revision, 'name' => 'Ayesha Khan', 'confirm' => '1'])->assertRedirect();

    return $sent;
}

it('shows the costs card with the quoted internal cost, and expenses against it', function () {
    // The helper's line carries no internal cost yet — the model must stay honestly blank.
    expect($this->project->quotedCostMinor())->toBeNull()
        ->and($this->project->spentMinor())->toBe(0);

    $this->project->quote->items()->first()->forceFill(['internal_cost_minor' => 600_000 * 100])->save();

    $this->project->expenses()->createMany([
        ['description' => 'Walnut veneer, 400 sqft', 'category' => 'material', 'qty' => 400, 'unit' => 'sqft', 'amount_minor' => 280_000 * 100, 'incurred_on' => '2026-11-20', 'created_by' => $this->pm->id],
        ['description' => 'Carpenter team, week 1', 'category' => 'labour', 'qty' => 1, 'unit' => null, 'amount_minor' => 90_000 * 100, 'incurred_on' => '2026-11-21', 'created_by' => $this->pm->id],
    ]);

    expect($this->project->quotedCostMinor())->toBe(600_000 * 100)
        ->and($this->project->spentMinor())->toBe(370_000 * 100);

    Livewire::actingAs($this->pm)
        ->test(ProjectShow::class, ['project' => $this->project])
        ->assertSee("Quote's internal cost", false)
        ->assertSee('Walnut veneer, 400 sqft')
        ->assertSee('Carpenter team, week 1')
        ->assertSee('Record expense');
});

it('records an expense through the form and keeps the numbers honest', function () {
    Livewire::actingAs($this->pm)
        ->test(ProjectShow::class, ['project' => $this->project])
        ->set('expenseDescription', 'Transport of panels to site')
        ->set('expenseCategory', 'transport')
        ->set('expenseQty', '2')
        ->set('expenseUnit', 'trips')
        ->set('expenseAmount', '12500.50')
        ->set('expenseIncurredOn', '2026-11-22')
        ->call('addExpense')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    $expense = ProjectExpense::query()->sole();
    expect($expense->description)->toBe('Transport of panels to site')
        ->and($expense->amount_minor)->toBe(1_250_050)
        ->and($expense->created_by)->toBe($this->pm->id)
        ->and($this->project->spentMinor())->toBe(1_250_050);

    // An empty line is refused, not guessed.
    Livewire::actingAs($this->pm)
        ->test(ProjectShow::class, ['project' => $this->project])
        ->call('addExpense')
        ->assertHasErrors(['expenseDescription', 'expenseAmount', 'expenseIncurredOn']);
});

it('keeps the pen to project managers: sales sees the numbers, only managers record', function () {
    $expense = $this->project->expenses()->create([
        'description' => 'Site cleaning', 'category' => 'other', 'qty' => 1, 'unit' => null,
        'amount_minor' => 8_000 * 100, 'incurred_on' => '2026-11-20', 'created_by' => $this->pm->id,
    ]);

    Livewire::actingAs($this->sales)
        ->test(ProjectShow::class, ['project' => $this->project])
        ->assertSee('Site cleaning')
        ->assertDontSee('Record expense');

    $this->actingAs($this->sales);

    Livewire::test(ProjectShow::class, ['project' => $this->project])
        ->call('addExpense')
        ->assertForbidden();

    Livewire::test(ProjectShow::class, ['project' => $this->project])
        ->call('deleteExpense', $expense->id)
        ->assertForbidden();
});

it('removes an expense line when it was recorded wrong', function () {
    $expense = $this->project->expenses()->create([
        'description' => 'Wrong entry', 'category' => 'other', 'qty' => 1, 'unit' => null,
        'amount_minor' => 500 * 100, 'incurred_on' => '2026-11-20', 'created_by' => $this->pm->id,
    ]);

    Livewire::actingAs($this->pm)
        ->test(ProjectShow::class, ['project' => $this->project])
        ->call('deleteExpense', $expense->id)
        ->assertDispatched('toast');

    expect($this->project->expenses()->count())->toBe(0)
        ->and($this->project->spentMinor())->toBe(0);
});
