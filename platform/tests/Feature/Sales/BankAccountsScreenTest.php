<?php

declare(strict_types=1);

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\RecalculateQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Models\BankAccount;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuoteLinks;
use App\Livewire\Admin\Sales\BankAccounts;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| CRM / Sales deep QA, part 1: where the money goes is finally editable.
|
| The dashboard told the owner to add bank accounts "under Invoices → Bank accounts"; the route did not exist, the
| table was empty on every install, and every invoice therefore printed no payment details. This screen is the one
| the hint meant, and the tests hold it to what matters: the account you save is the account the next invoice
| prints, a default is unique per business line, and nothing is ever deleted out from under an issued invoice.
*/

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();

    $this->owner = $this->userWithRole('owner');
    $this->sales = $this->userWithRole('sales');
});

it('adds a real bank account from the screen', function () {
    Livewire::actingAs($this->owner)
        ->test(BankAccounts::class)
        ->call('openForm')
        ->set('title', 'WOODEX INTERIOR')
        ->set('bankName', 'Meezan Bank')
        ->set('accountNumber', '01234567890123')
        ->set('iban', 'PK00MEZN0000000000000000')
        ->set('branch', 'DHA Phase 5, Lahore')
        ->set('businessLine', 'interior')
        ->set('isDefault', true)
        ->call('save')
        ->assertHasNoErrors();

    $account = BankAccount::query()->sole();

    expect($account->title)->toBe('WOODEX INTERIOR')
        ->and($account->bank_name)->toBe('Meezan Bank')
        ->and($account->account_number)->toBe('01234567890123')
        ->and($account->iban)->toBe('PK00MEZN0000000000000000')
        ->and($account->business_line)->toBe(BusinessLine::Interior)
        ->and($account->is_default)->toBeTrue()
        ->and($account->is_active)->toBeTrue();
});

it('keeps one default per business line', function () {
    Livewire::actingAs($this->owner)
        ->test(BankAccounts::class)
        ->call('openForm')
        ->set('title', 'WOODEX INTERIOR — main')
        ->set('bankName', 'Meezan Bank')
        ->set('businessLine', 'interior')
        ->set('isDefault', true)
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($this->owner)
        ->test(BankAccounts::class)
        ->call('openForm')
        ->set('title', 'WOODEX INTERIOR — second')
        ->set('bankName', 'Bank Alfalah')
        ->set('businessLine', 'interior')
        ->set('isDefault', true)
        ->call('save')
        ->assertHasNoErrors();

    $first = BankAccount::query()->where('title', 'WOODEX INTERIOR — main')->sole();
    $second = BankAccount::query()->where('title', 'WOODEX INTERIOR — second')->sole();

    expect($first->is_default)->toBeFalse()
        ->and($second->is_default)->toBeTrue();
});

it('deactivates an account instead of deleting it', function () {
    $account = BankAccount::query()->create([
        'business_line' => BusinessLine::Interior,
        'title' => 'WOODEX INTERIOR',
        'bank_name' => 'Meezan Bank',
        'is_default' => true,
        'is_active' => true,
    ]);

    Livewire::actingAs($this->owner)
        ->test(BankAccounts::class)
        ->call('toggle', $account->id);

    expect($account->refresh()->is_active)->toBeFalse()
        ->and(BankAccount::query()->count())->toBe(1);
});

it('lets a viewer read the list but not add or change an account', function () {
    Livewire::actingAs($this->sales)
        ->test(BankAccounts::class)
        ->assertOk()
        ->assertDontSee('Add bank account')
        ->call('openForm')
        ->assertForbidden();
});

it('shows the bank accounts tab on the invoices hub', function () {
    $this->actingAs($this->owner)
        ->get(route('admin.invoices.index', ['tab' => 'bank']))
        ->assertOk()
        ->assertSee('Bank accounts')
        ->assertSee('Add bank account');
});

it('prints the account saved here on the next invoice, and the invoice screen shows it', function () {
    Livewire::actingAs($this->owner)
        ->test(BankAccounts::class)
        ->call('openForm')
        ->set('title', 'WOODEX INTERIOR')
        ->set('bankName', 'Meezan Bank')
        ->set('accountNumber', '01234567890123')
        ->set('iban', 'PK00MEZN0000000000000000')
        ->set('businessLine', 'interior')
        ->set('isDefault', true)
        ->call('save')
        ->assertHasNoErrors();

    $account = BankAccount::query()->sole();

    $lead = Lead::factory()->create(['owner_id' => $this->sales->id, 'business_line' => 'interior', 'city' => 'Lahore', 'area' => 'Model Town']);
    $quote = app(CreateQuote::class)->handle($lead->contact, BusinessLine::Interior, $this->sales, $lead, null, 'Bank details job');
    $editor = app(QuoteEditor::class);
    $section = $quote->sections()->first() ?? $editor->addSection($quote, 'Lounge');
    $editor->addLine($section, ['description' => 'False ceiling', 'unit' => 'sqft', 'qty' => '1', 'rate_minor' => 100_000_00]);
    $quote->items()->where('rate_minor', 0)->each(fn ($item) => $editor->deleteLine($item));

    app(RecalculateQuote::class)->handle($quote->refresh(), $this->sales);
    $sent = app(SendQuote::class)->handle($quote->refresh(), $this->sales)->quote->refresh();

    $this->post(QuoteLinks::signed('quotes.portal.accept', $sent), [
        'revision' => $sent->revision,
        'name' => 'Ayesha Khan',
        'confirm' => '1',
    ]);

    $invoice = Invoice::query()->where('quote_id', $sent->id)->sole();

    expect($invoice->bank_account_id)->toBe($account->id);

    $this->actingAs($this->owner)
        ->get(route('admin.invoices.show', $invoice))
        ->assertOk()
        ->assertSee('WOODEX INTERIOR')
        ->assertSee('PK00MEZN0000000000000000');
});
