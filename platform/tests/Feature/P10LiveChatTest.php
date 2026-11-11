<?php

use App\Domain\Conversations\Enums\Channel;
use App\Domain\Conversations\Enums\MessageDirection;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\Message;
use App\Livewire\Admin\Conversations\LiveChatBell;
use App\Settings\NotificationSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

use function Pest\Livewire\livewire;

/*
| P10.2 (docs/27, owner decision P10-C): the live-chat presence in the admin header. A visitor
| texting the website bubble shows up on every admin page — unread count on the icon, a green dot
| while the visitor is active, one toast per new message. The drawer opens itself on a new message
| (owner request, 2026-10-20); it still opens on a click any other time. Nothing is sent anywhere automatically (A0): the only send is the staff
| reply a human typed, on a thread the visitor opened.
*/

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
    $this->actingAs($this->owner);
});

function webChat(array $attributes = []): Conversation
{
    return Conversation::factory()->create(array_merge([
        'channel' => Channel::WebChat->value,
        'unread_count' => 2,
        'last_direction' => 'in',
        'last_message_at' => now(),
        'last_preview' => 'Salam, kitchen ke lia rate chahiye.',
    ], $attributes));
}

/** A visitor message with a controllable timestamp — the bell watches inbound messages, not last_direction. */
function visitorMessage(Conversation $conversation, string $body, ?CarbonInterface $at = null): Message
{
    $at ??= now();

    return Message::query()->create([
        'conversation_id' => $conversation->id,
        'direction' => MessageDirection::In,
        'body' => $body,
        'delivered_at' => $at,
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}

it('stays out of the header for staff who cannot use the inbox', function () {
    $this->actingAs($this->userWithRole('editor'));

    livewire(LiveChatBell::class)
        ->assertDontSee('Website chat');
});

it('shows the unread count and the live-visitor dot', function () {
    webChat();

    livewire(LiveChatBell::class)
        ->assertSeeHtml('aria-label="Website chat (1 unread)"')
        ->assertSeeHtml('A visitor is chatting right now')
        ->assertSeeHtml('Salam, kitchen ke lia rate chahiye.');
});

it('marks the visitor quiet after two minutes', function () {
    webChat(['last_message_at' => now()->subMinutes(3)]);

    livewire(LiveChatBell::class)
        ->assertDontSee('A visitor is chatting right now');
});

it('lists website chats only — WhatsApp never enters the live drawer', function () {
    $web = webChat();
    Conversation::factory()->create(['last_direction' => 'in', 'unread_count' => 5, 'last_preview' => 'WhatsApp only thread']);

    livewire(LiveChatBell::class)
        ->assertSee($web->contact->name)
        ->assertDontSee('WhatsApp only thread');
});

it('never replays history as a toast', function () {
    $conversation = webChat(['last_message_at' => now()->subMinutes(5)]);
    visitorMessage($conversation, 'Kya rate hai?', now()->subMinutes(5));

    livewire(LiveChatBell::class)
        ->call('poll')
        ->assertNotDispatched('chat-alert');
});

it('toasts a new visitor message once and opens the drawer on it', function () {
    $conversation = webChat(['last_message_at' => now()->subMinutes(5)]);
    visitorMessage($conversation, 'Purana sawal', now()->subMinutes(5));

    $component = livewire(LiveChatBell::class);
    $component->call('poll')->assertNotDispatched('chat-alert')->assertSet('drawer', false);

    // The visitor texts again (owner request, 2026-10-20: the drawer opens itself on a new message).
    visitorMessage($conversation, 'Kitna time lagega?', now()->addSecond());

    $component->call('poll')
        ->assertDispatched('chat-alert')
        ->assertSet('drawer', true)
        ->assertSet('selectedId', $conversation->id)
        ->assertSeeHtml('Kitna time lagega?');

    // Announced once: the next tick stays quiet and does not re-open anything.
    $component->call('poll')->assertNotDispatched('chat-alert');
});

it('stops toasting once a teammate has read the thread', function () {
    $conversation = webChat(['last_message_at' => now()->subMinutes(5)]);

    $component = livewire(LiveChatBell::class);
    visitorMessage($conversation, 'New message', now()->addSecond());

    // Another teammate reads it in the inbox before our poll fires.
    $conversation->forceFill(['unread_count' => 0])->save();

    $component->call('poll')->assertNotDispatched('chat-alert');
});

it('opens the drawer on request, picks the newest thread and marks it read', function () {
    $older = webChat(['last_message_at' => now()->subHour(), 'last_preview' => 'Older thread']);
    $newest = webChat(['last_message_at' => now()]);

    livewire(LiveChatBell::class)
        ->call('openDrawer')
        ->assertSet('drawer', true)
        ->assertSet('selectedId', $newest->id);

    expect($newest->refresh()->unread_count)->toBe(0)
        ->and($older->refresh()->unread_count)->toBe(2);
});

it('answers the visitor from the drawer on the same thread', function () {
    $conversation = webChat();
    $conversation->messages()->create([
        'direction' => 'in',
        'type' => 'text',
        'body' => 'Salam, kitchen ke lia rate chahiye.',
    ]);

    $reply = Str::limit('Salam! Bhai kitchen ka rate 850/sqft hai — site visit kar dein?', 100);

    livewire(LiveChatBell::class)
        ->call('openDrawer')
        ->assertSee('Salam, kitchen ke lia rate chahiye.')
        ->set('draft', $reply)
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('draft', '');

    $conversation->refresh();

    expect($conversation->messages()->where('direction', 'out')->where('body', $reply)->exists())->toBeTrue()
        ->and($conversation->last_direction)->toBe('out')
        ->and($conversation->last_preview)->toBe($reply);
});

it('keeps the sound off by default and out of non-managers hands', function () {
    expect(app(NotificationSettings::class)->chat_alert_sound)->toBeFalse();

    // A staff member without settings.manage cannot flip the studio-wide switch.
    $this->actingAs($this->userWithRole('sales'));

    livewire(LiveChatBell::class)->call('toggleSound')->assertStatus(403);
    expect(app(NotificationSettings::class)->chat_alert_sound)->toBeFalse();
});

it('switches the sound on for the whole studio from the drawer', function () {
    $conversation = webChat(['last_message_at' => now()->subMinutes(5), 'last_preview' => 'Ding me']);

    livewire(LiveChatBell::class)->call('toggleSound');
    expect(app(NotificationSettings::class)->chat_alert_sound)->toBeTrue();

    // The toast carries the sound flag so the browser may ding once per message.
    $component = livewire(LiveChatBell::class);
    visitorMessage($conversation, 'Ding me', now()->addSeconds(5));
    $component->call('poll')->assertDispatched('chat-alert');

    livewire(LiveChatBell::class)->call('toggleSound');
    expect(app(NotificationSettings::class)->chat_alert_sound)->toBeFalse();
});

it('rides along on every admin page through the header', function () {
    webChat();

    $this->actingAs($this->owner)
        ->get('/admin/settings')
        ->assertOk()
        ->assertSee('Website chat (1 unread)');
});
