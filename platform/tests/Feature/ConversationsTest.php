<?php

use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Enums\MessageDirection;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Conversations\Enums\MessageType;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\Message;
use App\Domain\Crm\Models\Contact;
use App\Integrations\WhatsApp\Contracts\WhatsAppGateway;
use App\Integrations\WhatsApp\Fakes\FakeWhatsAppGateway;
use App\Livewire\Admin\Conversations\Inbox;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/*
| The conversations phase (P4), the part that carries the work: Meta's webhook is proved, replayed messages are
| ignored, a client's message becomes a conversation with a contact, an answer goes out inside the 24-hour window
| and is refused outside it, and a client who says stop is heard (CNV-001/002/005, WAP-002…WAP-006).
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
});

/** Meta's payload for one inbound text message, signed the way Meta signs it. */
function whatsappPayload(string $from = '923224007688', string $body = 'Assalam o Alaikum, I need a quotation.', string $id = 'wamid.INBOUND1', ?string $name = 'Bilal Mirza'): array
{
    return [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => 'ENTRY1',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => ['phone_number_id' => '1234567890'],
                    'contacts' => [['wa_id' => $from, 'profile' => ['name' => $name]]],
                    'messages' => [[
                        'from' => $from,
                        'id' => $id,
                        'timestamp' => (string) now()->timestamp,
                        'type' => 'text',
                        'text' => ['body' => $body],
                    ]],
                ],
            ]],
        ]],
    ];
}

function postWhatsapp(array $payload, string $secret = 'app-secret'): TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = 'sha256='.hash_hmac('sha256', (string) $body, $secret);

    return test()->call('POST', '/webhooks/whatsapp', [], [], [], [
        'HTTP_X_HUB_SIGNATURE_256' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], (string) $body);
}

it('answers Meta\'s handshake and refuses one with the wrong token', function () {
    $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=CHALLENGE123')
        ->assertOk()
        ->assertSee('CHALLENGE123');

    $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=CHALLENGE123')
        ->assertForbidden();
});

it('refuses a webhook that is not signed with the app secret', function () {
    postWhatsapp(whatsappPayload(), 'not-our-secret')->assertForbidden();

    $this->assertDatabaseCount('conversations', 0);
});

it('turns an inbound message into a contact and a conversation', function () {
    postWhatsapp(whatsappPayload())->assertOk();

    $contact = Contact::query()->where('whatsapp', '+92322400768 8')->orWhere('whatsapp', '923224007688')->first();
    $contact ??= Contact::query()->whereNotNull('whatsapp')->firstOrFail();

    expect($contact->name)->toBe('Bilal Mirza')
        ->and($contact->consent_whatsapp)->toBeTrue();

    $conversation = Conversation::query()->firstOrFail();

    expect($conversation->channel->value)->toBe('whatsapp')
        ->and($conversation->status)->toBe(ConversationStatus::Open)
        ->and($conversation->unread_count)->toBe(1)
        ->and($conversation->last_preview)->toContain('Assalam o Alaikum')
        ->and($conversation->isInWindow())->toBeTrue(); // a client's message opens 24 hours

    expect(Message::query()->sole())
        ->direction->toBe(MessageDirection::In)
        ->type->toBe(MessageType::Text)
        ->external_id->toBe('wamid.INBOUND1');
});

it('matches a second message from the same client to the same conversation', function () {
    postWhatsapp(whatsappPayload())->assertOk();
    postWhatsapp(whatsappPayload(body: 'And the kitchen too.', id: 'wamid.INBOUND2'))->assertOk();

    expect(Conversation::query()->count())->toBe(1)
        ->and(Contact::query()->count())->toBe(1)
        ->and(Message::query()->count())->toBe(2)
        ->and(Conversation::firstOrFail()->unread_count)->toBe(2);
});

it('ignores a replayed message instead of storing it twice', function () {
    postWhatsapp(whatsappPayload())->assertOk();
    postWhatsapp(whatsappPayload())->assertOk();

    expect(Message::query()->count())->toBe(1)
        ->and(Conversation::query()->count())->toBe(1);
});

it('hears an opt-out keyword and stops marketing consent', function () {
    $contact = Contact::factory()->create(['whatsapp' => '923224007688', 'phone' => '923224007688', 'consent_whatsapp' => true, 'consent_marketing' => true]);

    postWhatsapp(whatsappPayload(body: 'STOP', id: 'wamid.STOP1'))->assertOk();

    $contact->refresh();

    expect($contact->opted_out_at)->not->toBeNull()
        ->and($contact->consent_whatsapp)->toBeFalse()
        ->and($contact->consent_marketing)->toBeFalse();
});

it('reopens a closed conversation when the client writes again', function () {
    $contact = Contact::factory()->create(['whatsapp' => '923224007688', 'phone' => '923224007688']);
    $conversation = Conversation::factory()->for($contact)->closed()->create(['external_number' => '923224007688']);

    postWhatsapp(whatsappPayload(body: 'One more thing.', id: 'wamid.AGAIN'))->assertOk();

    expect($conversation->refresh()->status)->toBe(ConversationStatus::Open)
        ->and($conversation->closed_at)->toBeNull();
});

it('sends a reply inside the window and stores what came back from Meta', function () {
    $conversation = Conversation::factory()->create(['external_number' => '923224007688']);
    $user = $this->userWithRole('sales');

    Livewire::actingAs($user)
        ->test(Inbox::class)
        ->call('select', $conversation->id)
        ->set('draft', 'We will visit on Tuesday at 11.')
        ->call('send')
        ->assertHasNoErrors();

    $message = Message::query()->sole();

    expect($message->direction)->toBe(MessageDirection::Out)
        ->and($message->body)->toBe('We will visit on Tuesday at 11.')
        ->and($message->status)->toBe(MessageStatus::Sent)
        ->and($message->user_id)->toBe($user->id)
        ->and($this->gateway->sent[0]['to'])->toBe('923224007688')
        ->and($this->gateway->sent[0]['body'])->toBe('We will visit on Tuesday at 11.');
});

it('refuses a free-form reply outside the 24-hour window', function () {
    $conversation = Conversation::factory()->outOfWindow()->create();

    Livewire::actingAs($this->userWithRole('sales'))
        ->test(Inbox::class)
        ->call('select', $conversation->id)
        ->set('draft', 'Just checking in.')
        ->call('send');

    expect(Message::query()->count())->toBe(0)
        ->and($this->gateway->sent)->toBe([]);
});

it('saves a failed send with Meta\'s error instead of pretending it worked', function () {
    $this->gateway->fails('(#131030) Recipient phone number not in allowed list');
    $conversation = Conversation::factory()->create();

    Livewire::actingAs($this->userWithRole('sales'))
        ->test(Inbox::class)
        ->call('select', $conversation->id)
        ->set('draft', 'Hello again.')
        ->call('send');

    expect(Message::query()->sole())
        ->status->toBe(MessageStatus::Failed)
        ->error_message->toContain('#131030');
});

it('keeps an internal note out of the client\'s hands', function () {
    $conversation = Conversation::factory()->create();

    Livewire::actingAs($this->userWithRole('sales'))
        ->test(Inbox::class)
        ->call('select', $conversation->id)
        ->set('note', 'Client wants a darker wood — ask the joiner first.')
        ->call('addNote');

    expect(Message::query()->sole())
        ->type->toBe(MessageType::Note)
        ->isNote()->toBeTrue()
        ->and($this->gateway->sent)->toBe([]);
});

it('records delivered and read receipts from Meta, and never moves a status backwards', function () {
    $conversation = Conversation::factory()->create();
    $message = Message::factory()->for($conversation)->outbound()->create([
        'external_id' => 'wamid.OUT1',
        'status' => MessageStatus::Sent,
    ]);

    $status = fn (string $state) => postWhatsapp([
        'object' => 'whatsapp_business_account',
        'entry' => [['changes' => [['value' => [
            'messaging_product' => 'whatsapp',
            'statuses' => [['id' => 'wamid.OUT1', 'status' => $state, 'timestamp' => '1700000100']],
        ]]]]],
    ]);

    $status('delivered')->assertOk();
    expect($message->refresh()->status)->toBe(MessageStatus::Delivered)
        ->and($message->delivered_at)->not->toBeNull();

    $status('read')->assertOk();
    expect($message->refresh()->status)->toBe(MessageStatus::Read)
        ->and($message->read_at)->not->toBeNull();

    // An old status arriving late changes nothing.
    $status('delivered')->assertOk();
    expect($message->refresh()->status)->toBe(MessageStatus::Read);
});

it('downloads inbound media so a photograph outlives Meta\'s link', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['url' => 'https://lookaside.example/photo.jpg', 'mime_type' => 'image/jpeg', 'sha256' => 'abc', 'file_size' => 10], 200),
        'lookaside.example/*' => Http::response('binary-photo-bytes', 200),
        'lookaside.fbsbx.com/*' => Http::response('binary-photo-bytes', 200),
    ]);

    // The gateway is asked where the media lives: it answers with Meta's own short-lived link, and the bytes are
    // fetched from there and stored on our side.
    $this->gateway = new FakeWhatsAppGateway;

    postWhatsapp([
        'object' => 'whatsapp_business_account',
        'entry' => [['changes' => [['value' => [
            'messaging_product' => 'whatsapp',
            'contacts' => [['wa_id' => '923224007688', 'profile' => ['name' => 'Bilal Mirza']]],
            'messages' => [['from' => '923224007688', 'id' => 'wamid.PHOTO1', 'type' => 'image', 'image' => ['id' => 'MEDIA1', 'mime_type' => 'image/jpeg', 'caption' => 'The wall I mean']]],
        ]]]]],
    ])->assertOk();

    $message = Message::query()->sole();

    expect($message->type)->toBe(MessageType::Image)
        ->and($message->media_path)->not->toBeNull()
        ->and($message->media_mime)->toBe('image/jpeg');
});

it('shows the inbox to the team and hides it from a role that may not read client messages', function () {
    Conversation::factory()->count(2)->create(['last_preview' => 'Assalam o Alaikum, I need a quotation.']);

    $sales = $this->userWithRole('sales');
    $this->actingAs($sales)->get(route('admin.inbox'))->assertOk()->assertSee('Assalam o Alaikum');

    $accountant = $this->userWithRole('accountant');
    $this->actingAs($accountant)->get(route('admin.inbox'))->assertForbidden();
});

it('lets the team close a conversation and reopen it', function () {
    $conversation = Conversation::factory()->create();
    $user = User::factory()->create();

    Livewire::actingAs($this->userWithRole('sales'))
        ->test(Inbox::class)
        ->call('select', $conversation->id)
        ->call('close')
        ->assertHasNoErrors()
        ->call('reopen')
        ->assertHasNoErrors();

    expect($conversation->refresh())
        ->status->toBe(ConversationStatus::Open)
        ->closed_at->toBeNull();
});
