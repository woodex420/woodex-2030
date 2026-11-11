<?php

declare(strict_types=1);

use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\JournalEntry;
use App\Domain\Accounts\Models\JournalLine;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Projects\Models\Project;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\RecalculateQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Enums\MilestonePlanKey;
use App\Domain\Sales\Models\BankAccount;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuoteLinks;
use App\Livewire\Admin\Projects\ProjectShow;
use App\Models\User;
use Livewire\Livewire;

/*
| Slice I: a project cost now reaches the books. Before this, `project_expenses` was a private list on the job
| page — the margin knew what the work cost and the ledger did not, so the P&L and the job profit could not both
| be true. Recording a cost posts Dr the cost account / Cr accounts payable; removing it reverses that entry.
*/

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 24)->setTime(11, 0));

    $this->sales = $this->userWithRole('sales');
    $this->owner = $this->userWithRole('owner');
    $this->lead = Lead::factory()->create(['owner_id' => $this->sales->id, 'business_line' => 'interior', 'city' => 'Lahore', 'area' => 'Model Town']);

    BankAccount::query()->create(['business_line' => BusinessLine::Interior, 'title' => 'WOODEX INTERIOR', 'bank_name' => 'Bank Alfalah', 'account_number' => '0000-0000-0000', 'iban' => 'PK00ALFA0000000000000000', 'is_default' => true, 'is_active' => true]);

    $this->project = costedProject($this->lead, $this->sales);
});

/** A sent, accepted quotation worth PKR 1,000,000, and the project the acceptance opened. */
function costedProject(Lead $lead, User $sales): Project
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

    return Project::query()->sole();
}

it('posts a project cost to the ledger the moment it is recorded on the job', function () {
    Livewire::actingAs($this->owner)
        ->test(ProjectShow::class, ['project' => $this->project])
        ->set('expenseDescription', 'Walnut veneer, 400 sqft')
        ->set('expenseCategory', 'material')
        ->set('expenseQty', '400')
        ->set('expenseUnit', 'sqft')
        ->set('expenseAmount', '280000')
        ->set('expenseIncurredOn', '2026-11-20')
        ->call('addExpense')
        ->assertHasNoErrors();

    $expense = $this->project->expenses()->sole();
    $entry = JournalEntry::query()->where('source_type', 'project_expense')->where('source_id', $expense->id)->sole();
    $materials = Account::query()->where('code', '5010')->value('id');
    $payable = Account::query()->where('code', '2010')->value('id');

    expect($expense->journal_entry_id)->toBe($entry->id)
        ->and($expense->account_id)->toBe($materials)
        ->and((int) JournalLine::query()->where('journal_entry_id', $entry->id)->where('account_id', $materials)->value('debit_minor'))->toBe(280_000 * 100)
        ->and((int) JournalLine::query()->where('journal_entry_id', $entry->id)->where('account_id', $payable)->value('credit_minor'))->toBe(280_000 * 100)
        ->and($entry->project_id)->toBe($this->project->id);
});

it('reverses the journal when a recorded cost is removed instead of deleting the entry', function () {
    Livewire::actingAs($this->owner)
        ->test(ProjectShow::class, ['project' => $this->project])
        ->set('expenseDescription', 'Carpenter team, week 1')
        ->set('expenseCategory', 'labour')
        ->set('expenseQty', '1')
        ->set('expenseAmount', '90000')
        ->set('expenseIncurredOn', '2026-11-21')
        ->call('addExpense');

    $expense = $this->project->expenses()->sole();
    $entry = JournalEntry::query()->where('source_type', 'project_expense')->where('source_id', $expense->id)->sole();

    Livewire::actingAs($this->owner)
        ->test(ProjectShow::class, ['project' => $this->project])
        ->call('deleteExpense', $expense->id);

    expect($this->project->expenses()->count())->toBe(0)
        ->and($entry->refresh()->isReversed())->toBeTrue()
        ->and(JournalEntry::query()->where('reverses_entry_id', $entry->id)->exists())->toBeTrue()
        ->and(JournalEntry::query()->whereKey($entry->id)->exists())->toBeTrue();
});

it('keeps the books balanced after a project cost is posted and reversed', function () {
    Livewire::actingAs($this->owner)
        ->test(ProjectShow::class, ['project' => $this->project])
        ->set('expenseDescription', 'Transport to site')
        ->set('expenseCategory', 'transport')
        ->set('expenseQty', '2')
        ->set('expenseAmount', '15000')
        ->set('expenseIncurredOn', '2026-11-22')
        ->call('addExpense');

    $this->actingAs($this->owner)
        ->get(route('admin.accounts.trial'))
        ->assertOk()
        ->assertSee('Balanced.')
        ->assertDontSee('Out of balance');
});

it('converts decimal project costs without losing a paisa through binary floats', function (string $amount, int $expectedMinor) {
    Livewire::actingAs($this->owner)
        ->test(ProjectShow::class, ['project' => $this->project])
        ->set('expenseDescription', 'Synthetic exact-decimal cost')
        ->set('expenseCategory', 'material')
        ->set('expenseQty', '1')
        ->set('expenseAmount', $amount)
        ->set('expenseIncurredOn', '2026-11-20')
        ->call('addExpense')
        ->assertHasNoErrors();

    $expense = $this->project->expenses()->sole();
    $entry = JournalEntry::query()->where('source_type', 'project_expense')->where('source_id', $expense->id)->sole();
    expect($expense->amount_minor)->toBe($expectedMinor)
        ->and((int) $entry->lines()->sum('debit_minor'))->toBe($expectedMinor)
        ->and((int) $entry->lines()->sum('credit_minor'))->toBe($expectedMinor);
})->with([
    'ordinary decimal' => ['12500.50', 1250050],
    'half-up rounding' => ['1.005', 101],
    'integer precision beyond binary float' => ['90071992547409.93', 9007199254740993],
]);
