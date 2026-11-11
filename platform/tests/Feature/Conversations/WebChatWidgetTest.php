<?php

use App\Domain\Conversations\Actions\SendConversationMessage;
use App\Domain\Conversations\Enums\Channel;
use App\Domain\Conversations\Enums\MessageDirection;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\Message;
use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Crm\Jobs\AskForPhoneNumber;
use App\Domain\Crm\Mail\AskForNumberMail;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\Task;
use App\Integrations\WhatsApp\Contracts\WhatsAppGateway;
use App\Integrations\WhatsApp\Fakes\FakeWhatsAppGateway;
use App\Livewire\Admin\Conversations\Inbox;
use App\Livewire\Admin\Website\WebsiteSettings;
use App\Settings\SiteSettings;
use Database\Factories\Website\PageFactory;
use Database\Seeders\ThemeTokensSeeder;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
| P8.5 web chat widget: a chat bubble on the public site that runs a rule-based intake
| (name → number → service), creates the lead through the single entry point, and hands the
| thread to the team's Conversations inbox. The owner's switch (Website Settings) gates every
| piece of it — with it off nothing renders and the endpoints answer 404.
*/

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
    $this->seed(ThemeTokensSeeder::class);
    PageFactory::new()->published()->withSlug('/')->create();
});

function widgetOn(): void
{
    app(SiteSettings::class)->fill(['chat_widget_enabled' => true])->save();
}

function startChat(): object
{
    return test()->postJson('/api/public/chat/start');
}

function sendChat(string $token, string $body): object
{
    return test()->postJson('/api/public/chat/send', ['token' => $token, 'body' => $body]);
}

it('keeps the widget invisible while it is switched off', function () {
    // The pages are files: none of them carries the tag. /assets/site.js is the one script they share and it
    // loads /chat/widget.js, which answers a no-op while the widget is off (checked below).
    expect((string) file_get_contents(base_path('public/index.html')))->not->toContain('chat/widget.js');

    // The script URL serves a valid no-op (a cached page must never error), the data endpoints 404.
    $script = $this->get('/chat/widget.js')->assertOk();
    $this->assertStringNotContainsString('WX_CHAT', $script->content());
    $this->postJson('/api/public/chat/start')->assertNotFound();
    $this->postJson('/api/public/chat/send', ['token' => str()->random(40), 'body' => 'hello'])
        ->assertNotFound();
    $this->getJson('/api/public/chat/messages?token='.str()->random(40))->assertNotFound();
});

it('serves the script and greets a new visitor by name flow', function () {
    widgetOn();

    $this->get('/chat/widget.js')->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=utf-8');

    // The tag is not in the page: the v1 files load their one shared script, and that script loads the widget.
    expect((string) file_get_contents(base_path('public/assets/site.js')))->toContain("s.src = '/chat/widget.js'");

    $response = startChat()->assertOk()->assertJsonPath('ok', true);
    $token = $response->json('token');
    expect($token)->toBeString();

    $conversation = Conversation::query()->where('widget_token', $token)->firstOrFail();
    expect($conversation->channel)->toBe(Channel::WebChat)
        ->and($conversation->widget_state)->toBe('asked_name')
        ->and($conversation->messages()->count())->toBe(2); // greeting + "What's your name?"

    // Resuming with the same token returns the same thread, not a new one.
    $again = startChatWithToken($token);
    expect($again->json('token'))->toBe($token);
    expect(Conversation::query()->where('channel', Channel::WebChat->value)->count())->toBe(1);

    $messages = collect($again->json('messages'));
    expect($messages->firstWhere('who', 'bot')['body'])->toContain('Woodex');
});

function startChatWithToken(string $token): object
{
    return test()->postJson('/api/public/chat/start?token='.$token);
}

it('runs the intake: name, number, service — then the lead exists and the thread goes live', function () {
    Mail::fake();
    widgetOn();

    $token = startChat()->json('token');

    sendChat($token, 'Bilal Raza')->assertOk();
    $conversation = Conversation::query()->where('widget_token', $token)->firstOrFail();
    expect($conversation->subject)->toBe('Bilal Raza')
        ->and($conversation->widget_state)->toBe('asked_number');

    sendChat($token, '0321 1234567')->assertOk();
    $conversation->refresh();
    expect($conversation->widget_state)->toBe('asked_service');

    $reply = sendChat($token, 'Wardrobe for the master bedroom');
    $reply->assertOk();

    $conversation->refresh();
    expect($conversation->widget_state)->toBe('live')
        ->and($conversation->lead_id)->not->toBeNull()
        ->and($conversation->contact_id)->not->toBeNull();

    $lead = Lead::query()->find($conversation->lead_id);
    expect($lead->source)->toBe(LeadSource::WebsiteChat)
        ->and($lead->service)->toBe('Wardrobe for the master bedroom')
        ->and($lead->contact->name)->toBe('Bilal Raza')
        ->and($lead->contact->phone)->toBe('+923211234567');

    // The visitor typed a number into a chat box — that is not WhatsApp consent.
    expect($lead->contact->consent_whatsapp)->toBeFalse();

    // The intake's last turn promises the team will reply right here.
    $bodies = $conversation->messages()->pluck('body');
    expect($bodies->last())->toContain("that's everything I need");
});

it('accepts an email instead of a number and never asks for the number twice', function () {
    Mail::fake();
    widgetOn();

    $token = startChat()->json('token');
    sendChat($token, 'Sana Malik');
    sendChat($token, 'sana@example.com');

    $conversation = Conversation::query()->where('widget_token', $token)->firstOrFail();
    expect($conversation->widget_state)->toBe('asked_service');

    sendChat($token, 'Office fit-out in Gulberg');

    $conversation->refresh();
    $lead = Lead::query()->find($conversation->lead_id);
    expect($lead->contact->email)->toBe('sana@example.com')
        ->and($lead->contact->phone)->toBeNull();

    // The chat itself is the ask, so the follow-up task anchor exists and no email goes out.
    expect(Task::query()->where('lead_id', $lead->id)->where('title', AskForPhoneNumber::TASK_TITLE)->exists())->toBeTrue();
    Mail::assertNotQueued(AskForNumberMail::class);
    Mail::assertNotSent(AskForNumberMail::class);
});

it('re-asks for a number that is neither a number nor an email', function () {
    widgetOn();

    $token = startChat()->json('token');
    sendChat($token, 'Usman');
    $reply = sendChat($token, 'not-a-number');

    $conversation = Conversation::query()->where('widget_token', $token)->firstOrFail();
    expect($conversation->widget_state)->toBe('asked_number');

    $bodies = collect($reply->json('messages'))->pluck('body');
    expect($bodies->last())->toContain("doesn't look like a number");
});

it('lets the team reply from the inbox without a gateway and the widget polls it', function () {
    widgetOn();

    $token = startChat()->json('token');
    sendChat($token, 'Hina');
    sendChat($token, '0300 5550199');
    sendChat($token, 'Kitchen renovation');
    $conversation = Conversation::query()->where('widget_token', $token)->firstOrFail();

    $gateway = new FakeWhatsAppGateway;
    $this->app->instance(WhatsAppGateway::class, $gateway);

    $staff = $this->userWithRole('sales_manager');
    $message = app(SendConversationMessage::class)->handle($conversation, 'Assalam o Alaikum Hina, we received your enquiry — when can you take a call?', $staff);

    expect($message->user_id)->toBe($staff->id)
        ->and($message->status->value)->not->toBe('failed');

    // No WhatsApp was touched for a web chat thread.
    expect($gateway->sent)->toBe([]);

    // The poll returns the staff reply and marks the thread read for the visitor.
    $after = (int) $conversation->messages()->where('direction', 'in')->max('id');
    $poll = $this->getJson('/api/public/chat/messages?token='.$token.'&after='.$after)->assertOk();

    $polled = collect($poll->json('messages'));
    expect($polled->contains(fn (array $message): bool => $message['who'] === 'staff'
        && str_contains($message['body'], 'we received your enquiry')))->toBeTrue();

    expect($conversation->messages()->whereNotNull('read_at')->count())->toBeGreaterThan(0);
});

it('starts a fresh thread for an unknown token instead of failing', function () {
    widgetOn();

    $unknown = str()->random(40);
    $response = sendChat($unknown, 'Hello?');
    $response->assertOk();

    $token = Conversation::query()->where('channel', Channel::WebChat->value)->value('widget_token');
    expect($token)->toBe($unknown);

    $messages = collect($response->json('messages'));
    expect($messages->count())->toBeGreaterThanOrEqual(3); // greeting + name ask + the visitor's message
});

it('stores the visitor message even when the intake is already live', function () {
    widgetOn();

    $token = startChat()->json('token');
    sendChat($token, 'Adeel');
    sendChat($token, '0333 7771234');
    sendChat($token, 'TV wall');
    $conversation = Conversation::query()->where('widget_token', $token)->firstOrFail();

    $before = $conversation->messages()->count();
    sendChat($token, 'Actually, also the study room');

    expect($conversation->messages()->count())->toBe($before + 1);
    $last = $conversation->messages()->latest('id')->first();
    expect($last->direction)->toBe(MessageDirection::In);
    expect($conversation->messages()->where('body', 'Actually, also the study room')->exists())->toBeTrue();
});

it('bounds a message to two thousand characters', function () {
    widgetOn();

    $token = startChat()->json('token');
    sendChat($token, 'Adeel');
    sendChat($token, '0333 7771234');
    sendChat($token, 'TV wall');

    $conversation = Conversation::query()->where('widget_token', $token)->firstOrFail();
    $before = $conversation->messages()->count();

    // Two thousand exactly is fine and stored whole; anything longer is refused at the door.
    sendChat($token, str_repeat('a', 2000))->assertOk();
    expect(mb_strlen($conversation->messages()->latest('id')->first()->body))->toBe(2000);

    sendChat($token, str_repeat('b', 2500))->assertStatus(422);
    expect($conversation->messages()->count())->toBe($before + 1);
});

it('ships the loader to the static v1 pages as well', function () {
    // The 142 imported pages are real index.html files served straight from public/ (docs/11 §2),
    // never through the layout — their one shared script is /assets/site.js, so the loader lives there.
    $script = (string) file_get_contents(public_path('assets/site.js'));
    expect($script)->toContain("s.src = '/chat/widget.js'");
});

it('answers the loader with the widget only while the switch is on', function () {
    // The pages are files and cannot be re-rendered, so the switch changes the script they already load.
    $off = $this->get('/chat/widget.js')->assertOk()->getContent();

    $this->actingAs($this->owner);
    Livewire::test(WebsiteSettings::class)
        ->set('chat_widget_enabled', true)
        ->call('save')
        ->assertHasNoErrors();
    $this->app['auth']->forgetGuards();

    $on = $this->get('/chat/widget.js')->assertOk()->getContent();

    expect($on)->toContain('WX_CHAT')
        ->and($off)->not->toContain('WX_CHAT');
});

it('counts the visitor message as unread for the inbox', function () {
    widgetOn();

    $token = startChat()->json('token');
    sendChat($token, 'Kiran');
    sendChat($token, '0345 9990000');

    $conversation = Conversation::query()->where('widget_token', $token)->firstOrFail();
    expect($conversation->unread_count)->toBe(2); // name + number
    // The intake's follow-up question is the newest turn, so the list previews the bot, not the visitor.
    expect($conversation->last_direction)->toBe('out');
    expect($conversation->messages()->where('direction', 'in')->count())->toBe(2);
    expect($conversation->messages()->where('direction', 'out')->count())->toBeGreaterThanOrEqual(3);
});

it('shows web chat threads in the inbox and answers them there without a gateway', function () {
    widgetOn();

    $token = startChat()->json('token');
    sendChat($token, 'Fahad');
    sendChat($token, '0322 4000768');
    sendChat($token, 'Full home interior');
    $conversation = Conversation::query()->where('widget_token', $token)->firstOrFail();

    $gateway = new FakeWhatsAppGateway;
    $this->app->instance(WhatsAppGateway::class, $gateway);

    $sales = $this->userWithRole('sales');
    Livewire::actingAs($sales)
        ->test(Inbox::class)
        ->call('select', $conversation->id)
        ->assertSee('Website chat')
        ->set('draft', 'Assalam o Alaikum Fahad, thank you for the enquiry.')
        ->call('send')
        ->assertHasNoErrors();

    expect($conversation->messages()->where('user_id', $sales->id)->count())->toBe(1)
        ->and($gateway->sent)->toBe([]);
});
