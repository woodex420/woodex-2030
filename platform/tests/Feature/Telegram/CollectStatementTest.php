<?php

use App\Domain\Accounts\Models\JournalEntry;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\RecalculateQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Enums\MilestonePlanKey;
use App\Domain\Sales\Models\BankAccount;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuoteLinks;
use App\Domain\System\Models\TelegramLink;
use App\Integrations\Telegram\Fakes\FakeTelegramGateway;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| P8.2: /collect and /statement — the money commands. Collect is two-step by design: the first run
| answers with the invoice and the exact confirming command; only the repeat with `confirm`
| records, verifies, receipts and books the payment. /statement is staff-only text on the phone.
*/

beforeEach(function () {
    config(['services.telegram.webhook_secret' => 'hook-secret']);
    Storage::fake('local');
    Notification::fake();
    $this->telegram = FakeTelegramGateway::bind();

    $this->owner = $this->userWithRole('owner');
    TelegramLink::query()->create([
        'user_id' => $this->owner->id,
        'chat_id' => 500,
        'is_active' => true,
        'linked_at' => now(),
    ]);

    $this->sales = $this->userWithRole('sales');
    $this->lead = Lead::factory()->create(['owner_id' => $this->sales->id, 'business_line' => 'interior', 'city' => 'Lahore', 'area' => 'Model Town']);

    BankAccount::query()->create(['business_line' => BusinessLine::Interior, 'title' => 'WOODEX INTERIOR', 'bank_name' => 'Bank Alfalah', 'account_number' => '0000-0000-0000', 'iban' => 'PK00ALFA0000000000000000', 'is_default' => true, 'is_active' => true]);
});

function commandUpdate(int $updateId, int $chatId, string $text): array
{
    return [
        'update_id' => $updateId,
        'message' => [
            'message_id' => $updateId + 100,
            'chat' => ['id' => $chatId, 'type' => 'private'],
            'from' => ['id' => $chatId, 'first_name' => 'Imtiaz'],
            'text' => $text,
        ],
    ];
}

function sendCommand(string $text, int $chatId = 500): string
{
    $updateId = 2000 + count(test()->telegram->sent);

    test()->postJson('/webhooks/telegram/hook-secret', commandUpdate($updateId, $chatId, $text), headers: ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();

    $replies = test()->telegram->textsFor($chatId);

    return (string) end($replies);
}

function issuedInvoiceFor(object $test, int $rupees = 1_000_000): Invoice
{
    $quote = app(CreateQuote::class)->handle($test->lead->contact, BusinessLine::Interior, $test->sales, $test->lead, null, 'Lounge and kitchen');
    $editor = app(QuoteEditor::class);
    $section = $quote->sections()->first() ?? $editor->addSection($quote, 'Lounge');
    $editor->addLine($section, ['description' => 'Wall panelling, walnut veneer', 'unit' => 'sqft', 'qty' => '1', 'rate_minor' => $rupees * 100]);
    $quote->items()->where('rate_minor', 0)->each(fn ($item) => $editor->deleteLine($item));
    $editor->setMilestonePlan($quote->refresh(), MilestonePlanKey::Execution);

    app(RecalculateQuote::class)->handle($quote->refresh(), $test->sales);
    $sent = app(SendQuote::class)->handle($quote->refresh(), $test->sales)->quote->refresh();

    $test->post(QuoteLinks::signed('quotes.portal.accept', $sent), ['revision' => $sent->revision, 'name' => 'Ayesha Khan', 'confirm' => '1']);

    return Invoice::query()->where('quote_id', $sent->id)->sole();
}

it('lists the money commands in help', function () {
    expect(sendCommand('/help'))->toContain('/collect')->toContain('/statement');
});

it('walks the owner through a collect: summary first, then confirm books it', function () {
    $invoice = issuedInvoiceFor($this, 500_000);

    $summary = sendCommand('/collect '.$invoice->number.' 200000 cash');

    expect($summary)->toContain($invoice->number)
        ->toContain('Ready to collect')
        ->toContain('/collect '.$invoice->number.' 200000 cash confirm')
        ->and(Payment::query()->count())->toBe(0);

    $done = sendCommand('/collect '.$invoice->number.' 200000 cash confirm');

    expect($done)->toContain('Collected and receipted')
        ->toContain('booked in the ledger');

    $payment = Payment::query()->sole();

    expect($payment->status->value)->toBe('verified')
        ->and($payment->receipt_number)->not->toBeNull()
        ->and((int) $payment->amount_minor)->toBe(200_000_00)
        ->and(JournalEntry::query()->where('source_type', 'payment')->where('source_id', $payment->id)->exists())->toBeTrue()
        ->and((int) $invoice->refresh()->paid_minor)->toBe(200_000_00);
});

it('refuses to collect more than the outstanding balance', function () {
    $invoice = issuedInvoiceFor($this, 100_000);

    $reply = sendCommand('/collect '.$invoice->number.' 200000 cash confirm');

    expect($reply)->toContain('Not collected')
        ->and(Payment::query()->count())->toBe(0);
});

it('refuses to collect on an unknown invoice and explains the amount format', function () {
    $invoice = issuedInvoiceFor($this, 100_000);

    expect(sendCommand('/collect INV-9999-9999 50000 cash'))->toContain('no invoice INV-9999-9999')
        ->and(sendCommand('/collect '.$invoice->number.' lots cash'))->toContain('is not an amount');
});

it('keeps /collect away from roles that cannot record payments', function () {
    $sales = $this->userWithRole('sales');
    TelegramLink::query()->create(['user_id' => $sales->id, 'chat_id' => 700, 'is_active' => true, 'linked_at' => now()]);

    expect(sendCommand('/collect INV-2026-0001 1000 cash', 700))->toContain('only to the active owner');
});

it('states what a client owes and what they recently paid', function () {
    $invoice = issuedInvoiceFor($this, 400_000);
    $contact = $invoice->contact;

    sendCommand('/collect '.$invoice->number.' 150000 bank confirm');

    $reply = sendCommand('/statement '.$contact->name);

    expect($reply)->toContain('Statement — '.$contact->name)
        ->toContain($invoice->number)
        ->toContain('Rs 50,000') // 50% advance of 400,000 = 200,000, minus the 150,000 collected
        ->and($reply)->toContain('Recent receipts');
});

it('answers a statement for a name nobody has', function () {
    expect(sendCommand('/statement Nobody Like This'))->toContain('No client matches');
});

it('disambiguates duplicate names instead of guessing a client', function () {
    Contact::factory()->create(['name' => 'Ali Raza']);
    Contact::factory()->create(['name' => 'Ali Raza Jr']);

    expect(sendCommand('/statement Ali Raza'))->toContain('Several clients match');
});

it('shows the usage when /collect or /statement come empty', function () {
    expect(sendCommand('/collect'))->toContain('Collect like this')
        ->and(sendCommand('/statement'))->toContain('Whose statement?');
});
