<?php

use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\RequestQuoteApproval;
use App\Domain\Sales\Enums\DiscountType;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Enums\PaymentStatus;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Notifications\QuoteNotification;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\System\Enums\ApprovalStatus;
use App\Domain\System\Models\Approval;
use App\Domain\System\Models\TelegramLink;
use App\Domain\System\Telegram\Commands\ApproveCommand;
use App\Domain\System\Telegram\TelegramCallbackBus;
use App\Integrations\Telegram\Fakes\FakeTelegramGateway;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| TGM-005 (P5 step S3): the commands and buttons that DO things, not just read.
|
| The rule under test above all: Telegram is another keyboard for the same Woodex OS, not a second system —
| every action runs the same domain action the screen uses, under the presser's own permissions, and the
| refusal you would get on the screen is the refusal you get on the phone.
*/

beforeEach(function () {
    config(['services.telegram.webhook_secret' => 'hook-secret']);
    Storage::fake('local');
    Notification::fake();
    $this->telegram = FakeTelegramGateway::bind();

    // The linked owner can run everything; a linked sales manager requests the approval.
    $this->owner = $this->userWithRole('owner');
    TelegramLink::query()->create(['user_id' => $this->owner->id, 'chat_id' => 500, 'is_active' => true, 'linked_at' => now()]);

    $this->manager = $this->userWithRole('sales_manager', ['name' => 'Ayesha Khan']);
    TelegramLink::query()->create(['user_id' => $this->manager->id, 'chat_id' => 501, 'is_active' => true, 'linked_at' => now()]);

    $this->sales = $this->userWithRole('sales', ['name' => 'Bilal Ahmed']);
    TelegramLink::query()->create(['user_id' => $this->sales->id, 'chat_id' => 502, 'is_active' => true, 'linked_at' => now()]);
});

function actionUpdate(int $updateId, string $data, int $chatId): array
{
    return [
        'update_id' => $updateId,
        'callback_query' => [
            'id' => 'cb-'.$updateId,
            'from' => ['id' => $chatId, 'is_bot' => false],
            'data' => $data,
            'message' => [
                'message_id' => $updateId + 100,
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'from' => ['id' => $chatId, 'first_name' => 'Imtiaz'],
            ],
        ],
    ];
}

function runAction(string $text, int $chatId = 500): string
{
    $updateId = 2000 + count(test()->telegram->sent);

    test()->postJson('/webhooks/telegram/hook-secret', [
        'update_id' => $updateId,
        'message' => [
            'message_id' => $updateId + 100,
            'chat' => ['id' => $chatId, 'type' => 'private'],
            'from' => ['id' => $chatId, 'first_name' => 'Imtiaz'],
            'text' => $text,
        ],
    ], headers: ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();

    $replies = test()->telegram->textsFor($chatId);

    return (string) end($replies);
}

function pressButton(string $data, int $chatId = 500): string
{
    static $nextUpdateId = 3000;
    $updateId = ++$nextUpdateId;

    test()->postJson('/webhooks/telegram/hook-secret', actionUpdate($updateId, $data, $chatId), headers: ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();

    $replies = test()->telegram->textsFor($chatId);

    return (string) end($replies);
}

/** A draft quotation with a discount beyond the requester's limit, awaiting approval. */
function approvalWaiting(?User $requester = null): Approval
{
    $requester ??= test()->sales;
    $lead = Lead::factory()->create(['owner_id' => $requester->id]);
    $quote = app(CreateQuote::class)->handle($lead->contact, BusinessLine::Interior, $requester, $lead, null, 'Lounge');
    $editor = app(QuoteEditor::class);
    $section = $quote->sections()->first() ?? $editor->addSection($quote, 'Lounge');
    $editor->addLine($section, ['description' => 'Wall panelling', 'unit' => 'sqft', 'qty' => '100', 'rate_minor' => 1_000_000]);
    $quote->items()->where('rate_minor', 0)->each(fn ($item) => $editor->deleteLine($item));
    $editor->updateDetails($quote->refresh(), ['discount_type' => DiscountType::Percent, 'discount_value' => 2000]);

    return app(RequestQuoteApproval::class)->handle($quote->refresh(), $requester, 'Client is a repeat customer');
}

it('lists the approvals waiting and decides them from the phone', function () {
    $approval = approvalWaiting();

    $list = runAction('/approvals');

    expect($list)->toContain('#'.$approval->id)
        ->and($list)->toContain('asked by Bilal Ahmed')
        ->and($list)->toContain('repeat customer');

    $decision = runAction('/approve '.$approval->id);

    expect($decision)->toContain('Approved')
        ->and($approval->refresh()->status)->toBe(ApprovalStatus::Approved)
        ->and($approval->approvable->refresh()->status)->toBe(QuoteStatus::Approved)
        ->and($approval->decided_by)->toBe(test()->owner->id);

    Notification::assertSentTo(test()->sales, QuoteNotification::class);
});

it('declines with a reason, and refuses a decline without one', function () {
    $approval = approvalWaiting();

    expect(runAction('/approve '.$approval->id.' no'))->toContain('Say why you are declining');

    $decision = runAction('/approve '.$approval->id.' no the discount is too deep for this finish');

    expect($decision)->toContain('Declined')
        ->and($approval->refresh()->status)->toBe(ApprovalStatus::Rejected)
        ->and($approval->reason)->toBe('the discount is too deep for this finish');
});

it('keeps the screen rules on the phone: nobody approves their own request', function () {
    // A manager can approve in general — but not their own request. 20% is past their 10% limit too.
    $approval = approvalWaiting(test()->manager);

    $reply = runAction('/approve '.$approval->id, 501);

    expect($reply)->toContain('only to the active owner')
        ->and($approval->refresh()->status)->toBe(ApprovalStatus::Pending);
    // The original domain rule still holds independently of the stricter Telegram entry gate.
    expect(app(ApproveCommand::class)->handle($this->manager, (string) $approval->id))
        ->toContain('cannot approve your own request');
});

it('assigns a lead to a colleague, and asks when the name matches two people', function () {
    $lead = Lead::factory()->create(['owner_id' => $this->owner->id]);
    User::factory()->create(['name' => 'Ayesha Malik', 'is_active' => true])->assignRole('sales');

    $reply = runAction('/assign '.$lead->ref.' Ayesha');

    expect($reply)->toContain('Which one?')
        ->and($reply)->toContain('Ayesha Khan / Ayesha Malik')
        ->and($lead->refresh()->owner_id)->toBe($this->owner->id);

    $done = runAction('/assign '.$lead->ref.' Ayesha Khan');

    expect($done)->toContain('now belongs to Ayesha Khan')
        ->and($lead->refresh()->owner_id)->toBe($this->manager->id)
        ->and(Activity::query()->where('lead_id', $lead->id)->where('type', ActivityType::Assignment->value)->latest('id')->first()?->body)->toContain('from Telegram');
});

it('leaves a note on the lead timeline, marked as written from Telegram', function () {
    $lead = Lead::factory()->create(['owner_id' => $this->owner->id]);

    $reply = runAction('/note '.$lead->ref.' client prefers a call after 6 pm');

    expect($reply)->toContain('Noted on '.$lead->ref);

    $note = Activity::query()->where('lead_id', $lead->id)->where('type', ActivityType::Note->value)->sole();

    expect($note->body)->toBe('client prefers a call after 6 pm (via Telegram)')
        ->and($note->user_id)->toBe($this->owner->id);
});

it('answers an assign-to-me press on the new-lead alert, with the presser as the owner', function () {
    $lead = Lead::factory()->create(['owner_id' => null]);

    // The alert carries the button; the press is what we test.
    $reply = pressButton(TelegramCallbackBus::button('assign', $lead->id), 500);

    expect($reply)->toContain('is now yours')
        ->and($lead->refresh()->owner_id)->toBe($this->owner->id);

    // The toast on the button was answered too.
    expect(test()->telegram->answeredCallbacks)->not->toBeEmpty();
});

it('verifies a payment from the alert button, and refuses when it is not the presser job', function () {
    $lead = Lead::factory()->create(['owner_id' => $this->owner->id]);
    $payment = Payment::query()->create([
        'contact_id' => $lead->contact_id,
        'amount_minor' => 500_000_00,
        'received_on' => now()->toDateString(),
        'method' => PaymentMethod::BankTransfer->value,
        'status' => PaymentStatus::PendingVerification->value,
        'submitted_by' => 'client',
    ]);

    $reply = pressButton(TelegramCallbackBus::button('payverify', $payment->id), 502);

    expect($reply)->toBe('')
        ->and($payment->refresh()->status)->toBe(PaymentStatus::PendingVerification);

    $done = pressButton(TelegramCallbackBus::button('payverify', $payment->id), 500);

    expect($done)->toContain('Payment verified')
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Verified);
});

it('refuses a button that has expired, instead of acting on a changed world', function () {
    $lead = Lead::factory()->create(['owner_id' => null]);
    $expired = sprintf('assign:%d:%d', $lead->id, now()->subDay()->getTimestamp());

    expect(pressButton($expired))->toContain('has expired')
        ->and($lead->refresh()->owner_id)->toBeNull();
});

it('silently ignores an unlinked chat button', function () {
    $lead = Lead::factory()->create();

    expect(pressButton(TelegramCallbackBus::button('assign', $lead->id), 606))->toBe('');
});

it('keeps assign to a role that may assign', function () {
    $lead = Lead::factory()->create(['owner_id' => $this->owner->id]);

    // Support has leads.view but not leads.assign.
    $support = $this->userWithRole('support');
    TelegramLink::query()->create(['user_id' => $support->id, 'chat_id' => 503, 'is_active' => true, 'linked_at' => now()]);

    expect(runAction('/assign '.$lead->ref.' Bilal', 503))->toContain('only to the active owner')
        ->and($lead->refresh()->owner_id)->toBe($this->owner->id);
});
