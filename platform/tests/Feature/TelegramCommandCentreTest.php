<?php

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Enums\InvoiceKind;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\System\Models\TelegramLink;
use App\Integrations\Telegram\Fakes\FakeTelegramGateway;
use Illuminate\Support\Facades\Storage;

/*
| TGM-004 (P5 steps S1 + S2): the bot becomes the command centre.
|
| The framework rules and the read commands, tested the way they run: a webhook POST with a linked staff
| account, asserting on the reply the fake gateway carries. The owner's phone is not the test bench.
*/

beforeEach(function () {
    config(['services.telegram.webhook_secret' => 'hook-secret']);
    Storage::fake('local');
    $this->telegram = FakeTelegramGateway::bind();

    // A linked owner: the one role that may run every command.
    $this->owner = $this->userWithRole('owner');
    TelegramLink::query()->create([
        'user_id' => $this->owner->id,
        'chat_id' => 500,
        'is_active' => true,
        'linked_at' => now(),
    ]);
});

function centreUpdate(int $updateId, int $chatId, string $text): array
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

function centreSend(string $text, int $chatId = 500): string
{
    $updateId = 1000 + count(test()->telegram->sent);

    test()->postJson('/webhooks/telegram/hook-secret', centreUpdate($updateId, $chatId, $text), headers: ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();

    // The latest reply to this chat, not the first one it ever received.
    $replies = test()->telegram->textsFor($chatId);

    return (string) end($replies);
}

it('lists every command in help, generated from the registry so it cannot go stale', function () {
    $reply = centreSend('/help');

    foreach (['/today', '/leads', '/lead', '/find', '/quotes', '/quote', '/due', '/visits', '/report', '/health', '/mute', '/idea', '/issue'] as $command) {
        expect($reply)->toContain($command);
    }
});

it('answers an unknown command with the help pointer, not silence', function () {
    expect(centreSend('/fly-me-to-the-moon'))->toContain('Unknown command');
});

it('keeps business commands away from a chat that is not linked', function () {
    expect(centreSend('/today', 606))->toContain('not linked');
});

it('refuses a command the linked role does not hold', function () {
    $support = $this->userWithRole('support');
    TelegramLink::query()->create(['user_id' => $support->id, 'chat_id' => 501, 'is_active' => true, 'linked_at' => now()]);

    expect(centreSend('/due', 501))->toContain('only to the active owner');
});

it('shows the morning view: visits, overdue leads, tasks, payments, approvals', function () {
    $lead = Lead::factory()->create(['owner_id' => $this->owner->id, 'sla_due_at' => now()->subHour(), 'first_response_at' => null]);

    $reply = centreSend('/today');

    expect($reply)->toContain('Today')
        ->and($reply)->toContain($lead->ref)
        ->and($reply)->toContain('Visits:')
        ->and($reply)->toContain('Your tasks:')
        ->and($reply)->toContain('Approvals waiting:')
        ->and($reply)->toContain('Payments to verify:');
});

it('keeps payments and approvals out of /today for a role that cannot act on them', function () {
    $sales = $this->userWithRole('sales');
    TelegramLink::query()->create(['user_id' => $sales->id, 'chat_id' => 502, 'is_active' => true, 'linked_at' => now()]);

    $reply = centreSend('/today', 502);

    expect($reply)->toContain('only to the active owner')
        ->and($reply)->not->toContain('Payments to verify:')
        ->and($reply)->not->toContain('Approvals waiting:');
});

it('lists the latest leads and the unassigned slice', function () {
    $assigned = Lead::factory()->create(['owner_id' => $this->owner->id]);
    $floating = Lead::factory()->create(['owner_id' => null, 'lost_at' => null, 'won_at' => null]);

    $reply = centreSend('/leads');

    expect($reply)->toContain('Latest leads')
        ->and($reply)->toContain($assigned->ref)
        ->and($reply)->toContain($floating->ref);

    expect(centreSend('/leads unassigned'))->toContain('Unassigned leads')
        ->and(centreSend('/leads unassigned'))->toContain($floating->ref)
        ->and(centreSend('/leads unassigned'))->not->toContain($assigned->ref);
});

it('shows one lead by reference and finds it by phone in any format', function () {
    $lead = Lead::factory()->create(['owner_id' => $this->owner->id, 'expected_value_minor' => 2_500_000_00]);
    $lead->contact->update(['phone' => '+923224000768']);

    $byRef = centreSend('/lead '.$lead->ref);

    expect($byRef)->toContain($lead->ref)
        ->and($byRef)->toContain('Stage:')
        ->and($byRef)->toContain('Rs 2,500,000')
        ->and($byRef)->toContain('+92 322 4000768'); // stored E.164, shown the way people read it

    $byPhone = centreSend('/lead 0322 4000768');

    expect($byPhone)->toContain($lead->ref);

    expect(centreSend('/lead L-1999-000001'))->toContain('No lead matches');
});

it('hides a lead the caller may not see, as if it did not exist', function () {
    $sales = $this->userWithRole('sales');
    TelegramLink::query()->create(['user_id' => $sales->id, 'chat_id' => 503, 'is_active' => true, 'linked_at' => now()]);

    $someoneElses = Lead::factory()->create(['owner_id' => $this->owner->id]);

    expect(centreSend('/lead '.$someoneElses->ref, 503))->toContain('only to the active owner')->not->toContain($someoneElses->ref);
});

it('lists quotations with amounts and status', function () {
    $quote = telegramQuote();

    $reply = centreSend('/quotes');

    expect($reply)->toContain('Recent quotations')
        ->and($reply)->toContain($quote->label())
        ->and($reply)->toContain($quote->total()->format());

    expect(centreSend('/quote '.$quote->number))->toContain('Sent')
        ->and(centreSend('/quote WI-99999'))->toContain('No quotation');
});

/** A sent quotation with one priced line, the way the studio makes them. */
function telegramQuote(): Quote
{
    $lead = Lead::factory()->create(['owner_id' => test()->owner->id]);
    $quote = app(CreateQuote::class)->handle($lead->contact, BusinessLine::Interior, test()->owner, $lead, null, 'Lounge');
    $editor = app(QuoteEditor::class);
    $section = $quote->sections()->first() ?? $editor->addSection($quote, 'Lounge');
    $editor->addLine($section, ['description' => 'Wall panelling', 'unit' => 'sqft', 'qty' => '1', 'rate_minor' => 1_800_000_00]);
    $quote->items()->where('rate_minor', 0)->each(fn ($item) => $editor->deleteLine($item));
    app(SendQuote::class)->handle($quote->refresh(), test()->owner, 'link');

    return $quote->refresh();
}

it('lists overdue and upcoming invoices with balances', function () {
    $lead = Lead::factory()->create(['owner_id' => $this->owner->id]);
    $invoice = Invoice::query()->create([
        'number' => 'INV-2026-0001',
        'kind' => InvoiceKind::Milestone,
        'status' => InvoiceStatus::Issued,
        'contact_id' => $lead->contact_id,
        'business_line' => BusinessLine::Interior,
        'currency' => 'PKR',
        'subtotal_minor' => 5_000_000_00,
        'total_minor' => 5_000_000_00,
        'balance_minor' => 5_000_000_00,
        'issue_date' => now()->subDays(10)->toDateString(),
        'due_date' => now()->subDays(3)->toDateString(),
    ]);

    $reply = centreSend('/due');

    expect($reply)->toContain('Overdue:')
        ->and($reply)->toContain($invoice->number)
        ->and($reply)->toContain('Rs 5,000,000')
        ->and($reply)->toContain('day(s) late');
});

it('reports the period from the same tables the dashboard reads', function () {
    Lead::factory()->count(3)->create(['owner_id' => $this->owner->id, 'created_at' => now()]);

    $reply = centreSend('/report');

    expect($reply)->toContain('Today:')
        ->and($reply)->toContain('Leads: 3')
        ->and($reply)->toContain('Quotes sent:')
        ->and($reply)->toContain('Collected:');

    expect(centreSend('/report week'))->toContain('This week:');
});

it('shows system health in one message', function () {
    $reply = centreSend('/health');

    expect($reply)->toContain('Cron:')
        ->and($reply)->toContain('Queue:')
        ->and($reply)->toContain('Disk:')
        ->and($reply)->toContain('Integrations:');
});

it('mutes and unmutes the chat, using the same switch the alerts respect', function () {
    $reply = centreSend('/mute 8');

    expect($reply)->toContain('muted for 8 hour(s)')
        ->and($this->owner->fresh()->telegramLink->muted_until)->not->toBeNull();

    expect(centreSend('/mute off'))->toContain('on again')
        ->and($this->owner->fresh()->telegramLink->muted_until)->toBeNull();

    expect(centreSend('/mute forever'))->toContain('How many hours?');
});

it('refuses a chat that fires commands without pause, and Telegram retries later', function () {
    // The hardened webhook (agent security review, 17 Oct) rate-limits at the door: 30 commands per
    // minute per sender. The 31st gets a 429, so Telegram keeps it and retries — no reply is sent here.
    for ($i = 0; $i < 30; $i++) {
        centreSend('/help');
    }

    expect(test()->telegram->textsFor(500))->toHaveCount(30);

    $updateId = 1000 + count(test()->telegram->sent);

    test()->postJson('/webhooks/telegram/hook-secret', centreUpdate($updateId, 500, '/help'), headers: ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])
        ->assertStatus(429);

    expect(test()->telegram->textsFor(500))->toHaveCount(30);
});
