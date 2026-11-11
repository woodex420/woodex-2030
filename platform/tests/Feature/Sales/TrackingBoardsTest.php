<?php

use App\Domain\Accounts\Models\JournalEntry;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\LogQuoteEvent;
use App\Domain\Sales\Actions\RecalculateQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Enums\MilestonePlanKey;
use App\Domain\Sales\Models\BankAccount;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Services\ClientStatementPdf;
use App\Domain\Sales\Services\QuoteEditor;
use App\Integrations\WhatsApp\Contracts\WhatsAppGateway;
use App\Integrations\WhatsApp\Fakes\FakeWhatsAppGateway;
use App\Livewire\Admin\Sales\InvoiceTrackingBoard;
use App\Livewire\Admin\Sales\QuoteTrackingBoard;
use App\Models\User;
use App\Settings\SalesSettings;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| P8.4: the tracking boards + client statements. The boards read status, dates and events — they
| never invent states. Collecting from the invoice board is the P8.2 one-act collection (verified,
| receipted, booked). The statement PDF is the studio's letterhead record of what a client owes.
*/

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();

    $this->owner = $this->userWithRole('owner');
    $this->sales = $this->userWithRole('sales');
    $this->support = $this->userWithRole('support');
    $this->gateway = new FakeWhatsAppGateway;
    $this->app->instance(WhatsAppGateway::class, $this->gateway);
    $this->lead = Lead::factory()->create(['owner_id' => $this->sales->id, 'business_line' => 'interior', 'city' => 'Lahore', 'area' => 'Model Town']);
    $this->contact = $this->lead->contact;

    BankAccount::query()->create(['business_line' => BusinessLine::Interior, 'title' => 'WOODEX INTERIOR', 'bank_name' => 'Bank Alfalah', 'account_number' => '0000-0000-0000', 'iban' => 'PK00ALFA0000000000000000', 'is_default' => true, 'is_active' => true]);
});

/** A sent quotation awaiting the client's answer. */
function trackedQuote(object $test): Quote
{
    $quote = app(CreateQuote::class)->handle($test->contact, BusinessLine::Interior, $test->sales, $test->lead, null, 'Lounge and kitchen');
    $editor = app(QuoteEditor::class);
    $section = $quote->sections()->first() ?? $editor->addSection($quote, 'Lounge');
    $editor->addLine($section, ['description' => 'Wall panelling, walnut veneer', 'unit' => 'sqft', 'qty' => '1', 'rate_minor' => 500_000 * 100]);
    $quote->items()->where('rate_minor', 0)->each(fn ($item) => $editor->deleteLine($item));
    $editor->setMilestonePlan($quote->refresh(), MilestonePlanKey::Execution);

    app(RecalculateQuote::class)->handle($quote->refresh(), $test->sales);

    return app(SendQuote::class)->handle($quote->refresh(), $test->sales)->quote->refresh();
}

/** An issued invoice with the given balance situation. */
function invoiceFor(object $test, int $rupees, ?string $dueDate, ?string $issueDate = null): Invoice
{
    $owner = User::query()->role('owner')->first() ?? $test->owner;

    return Invoice::query()->create([
        'number' => 'INV-T-'.str_pad((string) (Invoice::query()->count() + 1), 4, '0', STR_PAD_LEFT),
        'kind' => 'manual',
        'status' => InvoiceStatus::Issued,
        'contact_id' => $test->contact->id,
        'currency' => 'PKR',
        'business_line' => 'interior',
        'subtotal_minor' => $rupees * 100,
        'tax_minor' => 0,
        'total_minor' => $rupees * 100,
        'paid_minor' => 0,
        'balance_minor' => $rupees * 100,
        'issue_date' => ($issueDate ?? today()->subDays(10)->toDateString()),
        'due_date' => $dueDate,
        'created_by' => $owner->id,
        'title' => 'Tracking board test',
    ]);
}

it('groups quotations the way the auto-chaser would', function () {
    $fresh = trackedQuote($this);

    $page = Livewire::actingAs($this->owner)
        ->test(QuoteTrackingBoard::class)
        ->assertOk();

    // Freshly sent: awaiting reply, not follow-up due (the default group is empty on day zero).
    $page->assertSet('group', 'follow_up_due')
        ->call('showGroup', 'awaiting_reply')
        ->assertSee($fresh->label());

    // After the follow-up period it becomes follow-up due (chaser rules: no chases yet).
    $this->travel(max(1, (int) app(SalesSettings::class)->quote_follow_up_days) + 1)->days();

    $this->actingAs($this->owner);
    $rendered = app(QuoteTrackingBoard::class)->render()->getData();
    expect($rendered['groups']['follow_up_due']['count'])->toBe(1)
        ->and($rendered['groups']['awaiting_reply']['count'])->toBe(0);
});

it('calls a quotation stale after three unanswered chases', function () {
    $quote = trackedQuote($this);
    $this->travel(20)->days();

    foreach ([1, 2, 3] as $chase) {
        app(LogQuoteEvent::class)->handle($quote, 'follow_up', $this->sales, "Follow-up: call {$chase}", ['total_minor' => (int) $quote->total_minor]);
    }

    $this->actingAs($this->owner);
    $rendered = app(QuoteTrackingBoard::class)->render()->getData();

    expect($rendered['groups']['stale']['count'])->toBe(1)
        ->and($rendered['groups']['follow_up_due']['count'])->toBe(0);
});

it('logs a chase from the board onto the quote timeline', function () {
    $quote = trackedQuote($this);

    Livewire::actingAs($this->sales)
        ->test(QuoteTrackingBoard::class)
        ->call('startChase', $quote->id)
        ->set('chaseNote', 'Called — reviewing with family, decides by Friday')
        ->call('logChase')
        ->assertHasNoErrors();

    expect($quote->events()->where('type', 'follow_up')->count())->toBe(1);

    // Support cannot chase.
    Livewire::actingAs($this->support)
        ->test(QuoteTrackingBoard::class)
        ->call('startChase', $quote->id)
        ->assertStatus(403);
});

it('groups invoices by real state and collects from the board in one act', function () {
    $overdue = invoiceFor($this, 400_000, today()->subDays(5)->toDateString());
    $dueSoon = invoiceFor($this, 200_000, today()->addDays(3)->toDateString());
    $current = invoiceFor($this, 100_000, today()->addDays(20)->toDateString());

    $this->actingAs($this->owner);
    $rendered = app(InvoiceTrackingBoard::class)->render()->getData();

    expect($rendered['groups']['overdue']['count'])->toBe(1)
        ->and($rendered['groups']['due_soon']['count'])->toBe(1)
        ->and($rendered['groups']['current']['count'])->toBe(1);

    // The owner collects Rs 150,000 on the overdue invoice straight from the board.
    Livewire::actingAs($this->owner)
        ->test(InvoiceTrackingBoard::class)
        ->assertSee($overdue->number)
        ->call('startCollect', $overdue->id)
        ->set('collectAmount', '150000')
        ->set('collectMethod', 'cash')
        ->call('collect')
        ->assertHasNoErrors();

    $payment = Payment::query()->sole();

    expect($payment->receipt_number)->not->toBeNull()
        ->and($payment->status->value)->toBe('verified')
        ->and((int) $overdue->refresh()->balance_minor)->toBe(250_000_00)
        ->and(JournalEntry::query()->where('source_type', 'payment')->where('source_id', $payment->id)->exists())->toBeTrue();
});

it('refuses to collect above the balance from the board', function () {
    $invoice = invoiceFor($this, 100_000, today()->subDays(2)->toDateString());

    Livewire::actingAs($this->owner)
        ->test(InvoiceTrackingBoard::class)
        ->call('startCollect', $invoice->id)
        ->set('collectAmount', '999999')
        ->set('collectMethod', 'cash')
        ->call('collect');

    expect(Payment::query()->count())->toBe(0);
});

it('keeps collecting behind the payments permissions', function () {
    $invoice = invoiceFor($this, 100_000, today()->subDays(2)->toDateString());

    Livewire::actingAs($this->sales) // sales can see the board but not verify payments
        ->test(InvoiceTrackingBoard::class)
        ->call('startCollect', $invoice->id)
        ->assertStatus(403);
});

it('renders a branded client statement PDF with the running truth', function () {
    $invoiceOne = invoiceFor($this, 400_000, today()->subDays(20)->toDateString());
    invoiceFor($this, 100_000, today()->addDays(10)->toDateString());

    // Collect Rs 150,000 against the first invoice through the board action.
    Livewire::actingAs($this->owner)
        ->test(InvoiceTrackingBoard::class)
        ->call('startCollect', $invoiceOne->id)
        ->set('collectAmount', '150000')
        ->set('collectMethod', 'cash')
        ->call('collect')
        ->assertHasNoErrors();

    expect(Payment::query()->count())->toBe(1);
    // The numbers on the statement come from the same data builder the PDF renders.
    $data = app(ClientStatementPdf::class)->data($this->contact);

    expect($data['total_invoiced_minor'])->toBe(500_000_00)
        ->and($data['total_received_minor'])->toBe(150_000_00)
        ->and($data['outstanding_minor'])->toBe(350_000_00)
        ->and($data['lines'])->toHaveCount(3) // two invoices + one receipt
        ->and($data['lines'][2]['balance'])->toBe('Rs 350,000')
        ->and($data['aging'][1]['amount'])->toBe('Rs 250,000'); // invoice one partially paid, 20 days overdue on the rest

    $response = $this->actingAs($this->owner)->get('/admin/clients/'.$this->contact->id.'/statement.pdf');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'inline; filename="'.ClientStatementPdf::filename($this->contact).'"');

    expect(substr((string) $response->getContent(), 0, 4))->toBe('%PDF');
});

it('sends a consented WhatsApp reminder from the invoice board', function () {
    $invoice = invoiceFor($this, 100_000, today()->subDays(3)->toDateString());
    $invoice->contact->forceFill(['consent_whatsapp' => true, 'whatsapp' => '+923224000768'])->save();

    Livewire::actingAs($this->owner)
        ->test(InvoiceTrackingBoard::class)
        ->call('remind', $invoice->id)
        ->assertDispatched('toast', message: 'Reminder sent to '.$invoice->contact->name.' on WhatsApp.', type: 'success');

    expect($this->gateway->sent)->not->toBeEmpty();
});

it('refuses to remind a client who never consented, straight from the board', function () {
    $invoice = invoiceFor($this, 100_000, today()->subDays(3)->toDateString());

    expect($invoice->contact->consent_whatsapp)->toBeFalse();

    Livewire::actingAs($this->owner)
        ->test(InvoiceTrackingBoard::class)
        ->call('remind', $invoice->id)
        ->assertDispatched('toast', message: 'Not sent — the client has not consented to WhatsApp messages — call them instead.', type: 'error');

    expect($this->gateway->sent)->toBeEmpty();
});

it('honours the statement period filter', function () {
    invoiceFor($this, 400_000, today()->subDays(20)->toDateString(), today()->subDays(10)->toDateString());
    invoiceFor($this, 100_000, today()->addDays(10)->toDateString(), today()->toDateString());

    $fromLastWeek = app(ClientStatementPdf::class)
        ->data($this->contact, today()->subDays(5)->toDateString(), null);

    // The first invoice predates the window; the second is current.
    expect($fromLastWeek['lines'])->toHaveCount(1)
        ->and($fromLastWeek['total_invoiced_minor'])->toBe(100_000_00);
});

it('returns a plain 404 for a statement of an unknown client', function () {
    $this->actingAs($this->owner)->get('/admin/clients/999999/statement.pdf')->assertNotFound();
});

it('keeps the statement route behind the invoices permission', function () {
    $this->get('/admin/clients/'.$this->contact->id.'/statement.pdf')->assertRedirect('/admin/login');

    $this->actingAs($this->support)->get('/admin/clients/'.$this->contact->id.'/statement.pdf')->assertForbidden();
});

it('shows the tracking screens in the navigation for the right roles', function () {
    $this->actingAs($this->owner)->get('/admin/quotes?tab=tracking')->assertOk();
    $this->actingAs($this->owner)->get('/admin/invoices?tab=tracking')->assertOk();

    $this->actingAs($this->support)->get('/admin/quotes?tab=tracking')->assertForbidden();
    $this->actingAs($this->support)->get('/admin/invoices?tab=tracking')->assertForbidden();
});
