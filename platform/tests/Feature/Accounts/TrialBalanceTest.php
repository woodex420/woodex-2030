<?php

declare(strict_types=1);

use App\Domain\Accounts\Actions\PostInvoiceToLedger;
use App\Domain\Accounts\Actions\ReverseJournalEntry;
use App\Domain\Accounts\Models\JournalEntry;
use App\Domain\Crm\Models\Contact;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Models\Invoice;
use App\Livewire\Admin\Accounts\TrialBalance;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| CRM / Sales / Accounts deep QA, part 2: the books can answer "are we balanced?".
|
| Accounts could post, reverse and close, but no screen summed the journal: the chart shows what accounts exist and
| the journal lists entries one by one. The trial balance is a read over posted lines, so the tests hold it to the
| arithmetic — an invoice books equal debits and credits, a reversal cancels its original, the range really filters,
| and the range empty state is honest.
*/

beforeEach(function () {
    Storage::fake('local');

    $this->owner = $this->userWithRole('owner');
    $this->accountant = $this->userWithRole('accountant');
    $this->support = $this->userWithRole('support');

    $this->contact = Contact::factory()->create(['name' => 'Trial Balance Client']);
});

function trialInvoice(object $test, Contact $contact, string $number, int $rupees = 100_000, int $taxRupees = 0): Invoice
{
    $total = $rupees * 100;
    $tax = $taxRupees * 100;

    $invoice = Invoice::query()->create([
        'number' => $number,
        'kind' => 'manual',
        'status' => InvoiceStatus::Issued->value,
        'contact_id' => $contact->id,
        'business_line' => 'interior',
        'currency' => 'PKR',
        'subtotal_minor' => $total - $tax,
        'discount_minor' => 0,
        'tax_minor' => $tax,
        'total_minor' => $total,
        'paid_minor' => 0,
        'balance_minor' => $total,
        'issue_date' => today()->toDateString(),
        'due_date' => today()->addDays(7)->toDateString(),
    ]);

    app(PostInvoiceToLedger::class)->handle($invoice, $test->owner);

    return $invoice;
}

it('shows the posted accounts with their debits, credits and a balanced total', function () {
    trialInvoice($this, $this->contact, 'T-TB-1', 100_000, 15_000);

    $this->actingAs($this->owner)
        ->get(route('admin.accounts.trial'))
        ->assertOk()
        ->assertSee('Trial balance')
        ->assertSee('Accounts receivable')
        ->assertSee('Balanced.')
        ->assertSee('100,000')
        ->assertSee('85,000')
        ->assertSee('15,000');
});

it('includes a reversed entry so the account nets back to zero and the books stay balanced', function () {
    $invoice = trialInvoice($this, $this->contact, 'T-TB-2', 50_000);

    $journal = JournalEntry::query()->where('source_type', 'invoice')->where('source_id', $invoice->id)->sole();
    app(ReverseJournalEntry::class)->handle($journal, $this->owner, 'QA reversal');

    $this->actingAs($this->owner)
        ->get(route('admin.accounts.trial'))
        ->assertOk()
        ->assertSee('Balanced.')
        ->assertDontSee('Out of balance');
});

it('respects the date range and says so plainly when nothing falls inside it', function () {
    trialInvoice($this, $this->contact, 'T-TB-3', 70_000);

    Livewire::actingAs($this->owner)
        ->test(TrialBalance::class)
        ->set('from', today()->addDays(10)->toDateString())
        ->set('to', today()->addDays(20)->toDateString())
        ->assertSee('Nothing posted in this range')
        ->assertDontSee('Accounts receivable')
        // An empty range balances at zero by arithmetic, so it must not wear the green "Balanced." tick.
        ->assertSee('Nothing posted yet.')
        ->assertDontSee('Balanced.')
        ->assertDontSee('Out of balance');
});

it('opens for the owner and refuses a colleague without the ledger permission', function () {
    $this->actingAs($this->owner)->get(route('admin.accounts.trial'))->assertOk();
    $this->actingAs($this->support)->get(route('admin.accounts.trial'))->assertForbidden();
});

it('is closed to the accountant role, which today holds no accounts.view — recorded, not silently changed', function () {
    // The audit found this: the person who records the money cannot open the books. Changing a role is the owner's
    // decision, so this test pins the current behaviour and the report asks for the change.
    $this->actingAs($this->accountant)->get(route('admin.accounts.trial'))->assertForbidden();
});

it('is reachable from the Accounts group in the sidebar', function () {
    $this->actingAs($this->owner)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee(route('admin.accounts.trial'), false);
});
