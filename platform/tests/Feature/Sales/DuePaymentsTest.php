<?php

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\RecalculateQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Enums\MilestonePlanKey;
use App\Domain\Sales\Models\BankAccount;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuoteLinks;
use App\Integrations\WhatsApp\Contracts\WhatsAppGateway;
use App\Integrations\WhatsApp\Fakes\FakeWhatsAppGateway;
use App\Livewire\Admin\Sales\DuePayments;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| P8.2: the dues screen — what the studio is owed, oldest first, with aging buckets and the clients
| carrying the most. Reminders are consent-gated (A0 law): no consent, no number or no connection is
| answered with an honest reason, never a silent skip or an unsolicited message.
*/

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();

    $this->owner = $this->userWithRole('owner');
    $this->sales = $this->userWithRole('sales');
    $this->gateway = new FakeWhatsAppGateway;
    $this->app->instance(WhatsAppGateway::class, $this->gateway);

    $this->lead = Lead::factory()->create(['owner_id' => $this->sales->id, 'business_line' => 'interior', 'city' => 'Lahore', 'area' => 'Model Town']);

    BankAccount::query()->create(['business_line' => BusinessLine::Interior, 'title' => 'WOODEX INTERIOR', 'bank_name' => 'Bank Alfalah', 'account_number' => '0000-0000-0000', 'iban' => 'PK00ALFA0000000000000000', 'is_default' => true, 'is_active' => true]);
});

function overdueInvoice(object $test, int $rupees = 300_000): Invoice
{
    $quote = app(CreateQuote::class)->handle($test->lead->contact, BusinessLine::Interior, $test->sales, $test->lead, null, 'Overdue job');
    $editor = app(QuoteEditor::class);
    $section = $quote->sections()->first() ?? $editor->addSection($quote, 'Lounge');
    $editor->addLine($section, ['description' => 'Ceiling work', 'unit' => 'sqft', 'qty' => '1', 'rate_minor' => $rupees * 100]);
    $quote->items()->where('rate_minor', 0)->each(fn ($item) => $editor->deleteLine($item));
    $editor->setMilestonePlan($quote->refresh(), MilestonePlanKey::Execution);

    app(RecalculateQuote::class)->handle($quote->refresh(), $test->sales);
    $sent = app(SendQuote::class)->handle($quote->refresh(), $test->sales)->quote->refresh();

    $test->post(QuoteLinks::signed('quotes.portal.accept', $sent), ['revision' => $sent->revision, 'name' => 'Ayesha Khan', 'confirm' => '1']);

    $invoice = Invoice::query()->where('quote_id', $sent->id)->sole();

    // Overdue: the whole timeline exists so the buckets have something to count.
    $invoice->forceFill(['due_date' => now()->subDays(10)->toDateString()])->save();

    return $invoice->refresh();
}

it('shows the dues screen with aging buckets and the client exposure', function () {
    $invoice = overdueInvoice($this);

    Livewire::actingAs($this->owner)
        ->test(DuePayments::class)
        ->assertOk()
        ->assertSee($invoice->number)
        ->assertSee('1–30 days overdue')
        ->assertSee('Top clients by exposure')
        ->assertSee('Remind');
});

it('refuses a reminder when the client never consented to WhatsApp', function () {
    $invoice = overdueInvoice($this);

    expect($invoice->contact->consent_whatsapp)->toBeFalse();

    Livewire::actingAs($this->owner)
        ->test(DuePayments::class)
        ->call('remind', $invoice->id)
        ->assertDispatched('toast', message: 'Not sent — the client has not consented to WhatsApp messages — call them instead.', type: 'error');

    expect($this->gateway->sent)->toBeEmpty();
});

it('refuses a reminder when WhatsApp is not connected', function () {
    $invoice = overdueInvoice($this);
    $invoice->contact->forceFill(['consent_whatsapp' => true, 'whatsapp' => '+923224000768'])->save();
    $this->gateway->configured(false);

    Livewire::actingAs($this->owner)
        ->test(DuePayments::class)
        ->call('remind', $invoice->id)
        ->assertDispatched('toast', message: 'Not sent — WhatsApp is not connected in Settings yet.', type: 'error');
});

it('sends a consented reminder inside the 24-hour window', function () {
    $invoice = overdueInvoice($this);
    $invoice->contact->forceFill(['consent_whatsapp' => true, 'whatsapp' => '+923224000768'])->save();
    Livewire::actingAs($this->owner)
        ->test(DuePayments::class)
        ->call('remind', $invoice->id)
        ->assertDispatched('toast', message: 'Reminder sent to '.$invoice->contact->name.' on WhatsApp.', type: 'success');

    expect($this->gateway->sent)->not->toBeEmpty();
});

it('keeps the dues screen behind the invoices permission', function () {
    $this->get('/admin/payments?tab=dues')->assertRedirect('/admin/login');

    $this->actingAs($this->owner)->get('/admin/payments?tab=dues')->assertOk();
});
