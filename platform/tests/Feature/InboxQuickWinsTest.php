<?php

use App\Domain\Conversations\Actions\ReceiveWhatsAppMessage;
use App\Domain\Conversations\Actions\SendConversationMessage;
use App\Domain\Conversations\Enums\MessageDirection;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\Message;
use App\Domain\Conversations\Notifications\ConversationMentionedNotification;
use App\Domain\Conversations\Notifications\ConversationSlaBreachedNotification;
use App\Domain\System\Notifications\WhatsAppQualityNotification;
use App\Domain\System\Notifications\WhatsAppUsageNotification;
use App\Integrations\WhatsApp\Actions\CheckWhatsAppQuality;
use App\Integrations\WhatsApp\Actions\CheckWhatsAppUsage;
use App\Integrations\WhatsApp\Contracts\WhatsAppGateway;
use App\Integrations\WhatsApp\Fakes\FakeWhatsAppGateway;
use App\Settings\IntegrationSettings;
use App\Settings\SalesSettings;
use Illuminate\Support\Facades\Notification;

/*
| The inbox quick wins (P4): @mentions in internal notes (CNV-003b), the conversation reply
| SLA (CNV-007), and WhatsApp health monitoring (WAP-008 cost meter, WAP-010 quality rating).
| Alerts are asserted through Notification::fake(); the Telegram delivery path itself is the
| same channel every other notification uses.
*/

beforeEach(function (): void {
    config()->set('services.whatsapp', [
        'token' => 'test-token',
        'phone_number_id' => '1234567890',
        'waba_id' => '987654321',
        'app_secret' => 'app-secret',
        'verify_token' => 'verify-me',
        'api_version' => 'v23.0',
        'api_base' => 'https://graph.facebook.com',
    ]);

    $this->gateway = new FakeWhatsAppGateway;
    $this->app->instance(WhatsAppGateway::class, $this->gateway);

    $this->owner = $this->userWithRole('owner');
});

/** Deliver one inbound WhatsApp text and return the conversation it belongs to. */
function receiveWhatsapp(object $test, string $body = 'Assalam o Alaikum, rate bata dein?', string $id = 'wamid.INBOUND1'): Conversation
{
    app(ReceiveWhatsAppMessage::class)->handle([
        'messaging_product' => 'whatsapp',
        'contacts' => [['wa_id' => '923224007688', 'profile' => ['name' => 'Bilal Mirza']]],
        'messages' => [['from' => '923224007688', 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $body]]],
    ]);

    return Conversation::query()->sole();
}

it('notifies a teammate mentioned in an internal note', function () {
    Notification::fake();

    $sara = $this->userWithRole('sales', ['name' => 'Sara Khan', 'email' => 'sara.khan@woodex.test']);
    $conversation = receiveWhatsapp($this);

    $note = app(SendConversationMessage::class)->note($conversation, '@Sara please call this client — he wants the wardrobe quote', $this->owner);

    expect($note->type->value)->toBe('note')
        ->and($note->meta['mentions'] ?? null)->toBe([$sara->id]);

    Notification::assertSentTo($sara, ConversationMentionedNotification::class);
    Notification::assertNotSentTo($this->owner, ConversationMentionedNotification::class);
});

it('leaves a plain note alone', function () {
    Notification::fake();

    $sara = $this->userWithRole('sales', ['name' => 'Sara Khan', 'email' => 'sara.khan@woodex.test']);
    $conversation = receiveWhatsapp($this);

    $note = app(SendConversationMessage::class)->note($conversation, 'Client prefers walnut finish, decide by Friday.', $this->owner);

    expect($note->meta)->toBeNull();

    Notification::assertNotSentTo($sara, ConversationMentionedNotification::class);
});

it('arms the first-reply clock, then the shorter next-reply clock', function () {
    $settings = app(SalesSettings::class);
    $settings->conversation_first_reply_minutes = 30;
    $settings->conversation_next_reply_minutes = 15;
    $settings->save();

    $conversation = receiveWhatsapp($this, 'First message', 'wamid.A');

    expect(now()->diffInMinutes($conversation->reply_due_at))->toBeGreaterThanOrEqual(29);

    // A staff reply answers it…
    app(SendConversationMessage::class)->handle($conversation, 'Ji bilkul, share kar deta hoon.', $this->owner);

    expect($conversation->refresh()->reply_due_at)->toBeNull();

    // …and the next inbound message arms the shorter clock.
    app(ReceiveWhatsAppMessage::class)->handle([
        'messaging_product' => 'whatsapp',
        'contacts' => [['wa_id' => '923224007688', 'profile' => ['name' => 'Bilal Mirza']]],
        'messages' => [['from' => '923224007688', 'id' => 'wamid.B', 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'Shukriya!']]],
    ]);

    expect(now()->diffInMinutes($conversation->refresh()->reply_due_at))->toBeLessThanOrEqual(15);
});

it('flags a missed reply once, alerts the assignee, and escalates when it stays silent', function () {
    Notification::fake();

    $assignee = $this->userWithRole('sales');
    $conversation = receiveWhatsapp($this);
    $conversation->forceFill(['assignee_id' => $assignee->id, 'reply_due_at' => now()->subMinutes(40)])->save();

    $settings = app(SalesSettings::class);
    $settings->sla_escalation_minutes = 10;
    $settings->save();

    expect(test()->artisan('woodex:sla-check'))->toBe(0);

    Notification::assertSentTo($assignee, ConversationSlaBreachedNotification::class, fn ($notification) => $notification->escalated === false);
    Notification::assertSentTo($this->owner, ConversationSlaBreachedNotification::class, fn ($notification) => $notification->escalated === false);
    expect($conversation->refresh()->reply_sla_breached_at)->not->toBeNull();

    // A second run never re-alerts.
    Notification::fake();
    expect(test()->artisan('woodex:sla-check'))->toBe(0);
    Notification::assertNotSentTo($assignee, ConversationSlaBreachedNotification::class);

    // Still unanswered after the escalation delay: the final roles hear about it.
    $conversation->forceFill(['reply_sla_breached_at' => now()->subMinutes(30)])->save();

    expect(test()->artisan('woodex:sla-check'))->toBe(0);

    Notification::assertSentTo($this->owner, ConversationSlaBreachedNotification::class, fn ($notification) => $notification->escalated === true);
    expect($conversation->refresh()->reply_sla_escalated_at)->not->toBeNull();
});

it('alerts the integrations managers the day Meta downgrades the number', function () {
    Notification::fake();

    $settings = app(IntegrationSettings::class);
    $settings->whatsapp_quality_rating = 'GREEN';
    $settings->save();

    // Still GREEN: nothing happens, the stored rating just stays.
    $result = app(CheckWhatsAppQuality::class)->handle();

    expect($result['downgraded'])->toBeFalse();
    Notification::assertNothingSent();

    // Meta moves to YELLOW.
    $this->gateway->qualityRating = 'YELLOW';

    $result = app(CheckWhatsAppQuality::class)->handle();

    expect($result['downgraded'])->toBeTrue()
        ->and($result['rating'])->toBe('YELLOW')
        ->and($settings->refresh()->whatsapp_quality_rating)->toBe('YELLOW');

    Notification::assertSentTo($this->owner, WhatsAppQualityNotification::class);
});

it('counts the month, prices it, and alerts once when the budget is crossed', function () {
    Notification::fake();

    $settings = app(IntegrationSettings::class);
    $settings->whatsapp_text_cost = 5;
    $settings->whatsapp_template_cost = 15;
    $settings->whatsapp_monthly_budget = 100;
    $settings->save();

    $conversation = receiveWhatsapp($this);

    // 10 sent texts (50), 5 sent templates (75) → 125 against a budget of 100.
    foreach (range(1, 10) as $i) {
        $conversation->messages()->create(['direction' => MessageDirection::Out, 'type' => 'text', 'body' => 'reply '.$i, 'status' => MessageStatus::Sent, 'sent_at' => now()]);
    }
    foreach (range(1, 5) as $i) {
        $conversation->messages()->create(['direction' => MessageDirection::Out, 'type' => 'template', 'body' => 'quote_ready_v2', 'status' => MessageStatus::Sent, 'sent_at' => now()]);
    }
    // Excluded: a failed send, an internal note, and a website-chat message.
    $conversation->messages()->create(['direction' => MessageDirection::Out, 'type' => 'text', 'body' => 'never arrived', 'status' => MessageStatus::Failed]);
    $conversation->messages()->create(['direction' => MessageDirection::Out, 'type' => 'note', 'body' => 'internal only', 'status' => MessageStatus::Sent]);
    $webChat = Conversation::create(['channel' => 'web_chat', 'status' => 'open', 'last_direction' => 'in', 'unread_count' => 0, 'last_message_at' => now(), 'widget_token' => 'tok', 'last_preview' => 'chat']);
    $webChat->messages()->create(['direction' => MessageDirection::Out, 'type' => 'text', 'body' => 'web chat reply', 'status' => MessageStatus::Sent, 'sent_at' => now()]);

    $result = app(CheckWhatsAppUsage::class)->handle();

    expect($result['text'])->toBe(10)
        ->and($result['template'])->toBe(5)
        ->and($result['estimated_cost'])->toBe(125)
        ->and($result['alerted'])->toBeTrue()
        ->and($settings->refresh()->whatsapp_budget_alerted_month)->toBe(now()->format('Y-m'));

    Notification::assertSentTo($this->owner, WhatsAppUsageNotification::class, fn ($n) => $n->cost === 125 && $n->budget === 100);

    // The alert fires once a month, not once a day.
    Notification::fake();
    $again = app(CheckWhatsAppUsage::class)->handle();

    expect($again['alerted'])->toBeFalse();
    Notification::assertNothingSent();
});
