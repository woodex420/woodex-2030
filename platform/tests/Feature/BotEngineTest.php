<?php

use App\Domain\Bot\Fakes\FakeBotProvider;
use App\Domain\Bot\Models\BotKnowledge;
use App\Domain\Bot\Models\BotUsage;
use App\Domain\Bot\Providers\AnthropicProvider;
use App\Domain\Conversations\Actions\ReceiveWebChatMessage;
use App\Domain\Conversations\Actions\StartWebChat;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\System\Notifications\BotHandoffNotification;
use App\Livewire\Admin\System\SettingsPage;
use App\Settings\BotSettings;
use App\Settings\BusinessSettings;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
| BOT-002/003/006/007/008/010: the website assistant. The engine is proven end-to-end through the
| widget's own flow — the intake runs, the thread goes live, and the bot decides. A fake brain
| stands in for Claude so the tests see exactly what the model would have been told; the keyless
| mode is tested as itself, because that is what ships.
*/

beforeEach(function (): void {
    $this->owner = $this->userWithRole('owner');
    Notification::fake();

    // Wide-open studio hours, so the outside-hours-only tests can control the clock.
    $business = app(BusinessSettings::class);
    $business->opens_at = '00:00';
    $business->closes_at = '23:59';
    $business->working_days = [1, 2, 3, 4, 5, 6, 7]; // every day, so the clock is the only variable
    $business->save();

    $this->bot = app(BotSettings::class);
    $this->bot->enabled = true;
    $this->bot->provider = 'none';
    $this->bot->save();
});

/** Walk the widget's intake to a live thread: name → number → service. */
function liveChat(): Conversation
{
    $conversation = app(StartWebChat::class)->handle(null);
    $receive = app(ReceiveWebChatMessage::class);
    $receive->handle($conversation->refresh(), 'Bilal Mirza');
    $receive->handle($conversation->refresh(), '0322 4000768');
    $receive->handle($conversation->refresh(), 'A wardrobe for the master bedroom');

    return $conversation->refresh();
}

it('answers keyless: an honest reply, and a human hears about it', function () {
    $conversation = liveChat();
    expect($conversation->widget_state)->toBe('live');

    app(ReceiveWebChatMessage::class)->handle($conversation->refresh(), 'What are your timings?');

    $botReply = $conversation->messages()->where('meta->bot', true)->latest('id')->first();

    expect($botReply)->not->toBeNull()
        ->and($botReply->body)->toBe('Thank you! Our team has your message and will reply right here.')
        ->and($conversation->refresh()->status)->toBe(ConversationStatus::Pending);

    Notification::assertSentTo($this->owner, BotHandoffNotification::class, fn ($n) => str_contains($n->reason, 'no provider key'));

    $usage = BotUsage::query()->first();
    expect($usage->requests)->toBe(1)
        ->and($usage->handoffs)->toBe(1)
        ->and($usage->replies)->toBe(0);
});

it('thinks with a provider, feeds it the knowledge, and counts the tokens', function () {
    BotKnowledge::create([
        'title' => 'Wardrobe pricing',
        'content' => 'Woodex wardrobes start from Rs 150,000 for a two-door laminate wardrobe, including design and installation.',
        'source' => 'manual', 'is_active' => true,
    ]);

    $fake = new FakeBotProvider;
    $fake->reply = 'Our wardrobes start from Rs 150,000, including design and installation.';
    app()->instance(AnthropicProvider::class, $fake);

    $this->bot->provider = 'anthropic';
    $this->bot->api_key = 'test-key';
    $this->bot->save();

    $conversation = liveChat();
    app(ReceiveWebChatMessage::class)->handle($conversation->refresh(), 'How much does a wardrobe cost?');

    $botReply = $conversation->messages()->where('meta->bot', true)->latest('id')->first();

    expect($botReply->body)->toBe('Our wardrobes start from Rs 150,000, including design and installation.')
        ->and($botReply->meta['tokens'])->toBe(165);

    // The model was told the studio's own words, and the visitor's question.
    expect($fake->calls)->toHaveCount(1)
        ->and($fake->calls[0]['system'])->toContain('Wardrobe pricing')
        ->and($fake->calls[0]['system'])->toContain('Rs 150,000')
        ->and($fake->calls[0]['messages'])->not->toBeEmpty();

    $usage = BotUsage::query()->first();
    expect($usage->replies)->toBe(1)
        ->and($usage->tokens_in)->toBe(120)
        ->and($usage->tokens_out)->toBe(45)
        ->and($usage->handoffs)->toBe(0);
});

it('stops a reply that breaks the guardrails, and hands the thread over', function () {
    $fake = new FakeBotProvider;
    $fake->reply = 'We will deliver by Friday, and I can offer you a 10% discount — the total is Rs 555,000.';
    app()->instance(AnthropicProvider::class, $fake);

    $this->bot->provider = 'anthropic';
    $this->bot->api_key = 'test-key';
    $this->bot->save();

    $conversation = liveChat();
    app(ReceiveWebChatMessage::class)->handle($conversation->refresh(), 'Can you do it cheaper and fast?');

    $botReply = $conversation->messages()->where('meta->bot', true)->latest('id')->first();

    expect($botReply->body)->toBe('For exact prices and timelines, our team will confirm with you personally — a designer will reply here shortly.')
        ->and($botReply->meta['guardrails'])->toContain('promised a timeline')
        ->and($botReply->meta['guardrails'])->toContain('offered a discount')
        ->and($botReply->meta['guardrails'])->toContain('quoted a price the knowledge does not carry');

    Notification::assertSentTo($this->owner, BotHandoffNotification::class);

    $usage = BotUsage::query()->first();
    expect($usage->guardrail_hits)->toBe(1);
});

it('lets a price through when the knowledge carries it', function () {
    BotKnowledge::create([
        'title' => 'Wardrobe pricing',
        'content' => 'Wardrobes start from Rs 150,000.',
        'source' => 'manual', 'is_active' => true,
    ]);

    $fake = new FakeBotProvider;
    $fake->reply = 'A two-door wardrobe starts from Rs 150,000.';
    app()->instance(AnthropicProvider::class, $fake);

    $this->bot->provider = 'anthropic';
    $this->bot->api_key = 'test-key';
    $this->bot->save();

    $conversation = liveChat();
    app(ReceiveWebChatMessage::class)->handle($conversation->refresh(), 'Price for a wardrobe?');

    $botReply = $conversation->messages()->where('meta->bot', true)->latest('id')->first();

    expect($botReply->meta['guardrails'] ?? null)->toBeNull()
        ->and(BotUsage::query()->first()->guardrail_hits)->toBe(0);
});

it('hands over after the thread has had its share of bot replies', function () {
    $this->bot->conversation_turn_limit = 1;
    $this->bot->save();

    $conversation = liveChat();

    // The intake's own bot messages count: one bot reply has already happened on this thread…
    $botMessages = $conversation->messages()->where('meta->bot', true)->count();
    expect($botMessages)->toBeGreaterThan(0);

    // …so with a limit of 1, the live question goes straight to a human, keylessly and honestly.
    app(ReceiveWebChatMessage::class)->handle($conversation->refresh(), 'Anyone there?');

    expect($conversation->messages()->where('meta->bot', true)->latest('id')->first()->meta['guardrails'] ?? 'none')->toBe('none')
        ->and($conversation->refresh()->status)->toBe(ConversationStatus::Pending);

    Notification::assertSentTo($this->owner, BotHandoffNotification::class, fn ($n) => str_contains($n->reason, 'limit'));
});

it('stays silent during studio hours when it is an evenings-only bot', function () {
    $this->bot->outside_hours_only = true;
    $this->bot->save();

    $conversation = liveChat();
    $before = $conversation->messages()->count();

    app(ReceiveWebChatMessage::class)->handle($conversation->refresh(), 'Are you open now?');

    // No new bot message — the visitor's message and nothing else; the team was pinged.
    expect($conversation->refresh()->messages()->where('meta->bot', true)->count())->toBe($conversation->messages()->where('meta->bot', true)->count())
        ->and($conversation->status)->toBe(ConversationStatus::Pending);

    Notification::assertSentTo($this->owner, BotHandoffNotification::class, fn ($n) => str_contains($n->reason, 'studio hours'));
});

it('keeps the visitor message when the model itself fails', function () {
    $fake = new FakeBotProvider;
    $fake->fails = true;
    app()->instance(AnthropicProvider::class, $fake);

    $this->bot->provider = 'anthropic';
    $this->bot->api_key = 'test-key';
    $this->bot->save();

    $conversation = liveChat();
    app(ReceiveWebChatMessage::class)->handle($conversation->refresh(), 'Hello?');

    $last = $conversation->messages()->latest('id')->first();

    expect($last->body)->toBe('Hello?') // the visitor's message survives a failed brain
        ->and($conversation->refresh()->status)->toBe(ConversationStatus::Pending);
});

it('renders and saves the Bot tab', function () {
    Livewire::actingAs($this->owner)
        ->test(SettingsPage::class)
        ->set('tab', 'bot')
        ->assertOk()
        ->assertSee('Website assistant')
        ->assertSee('a human will reply')
        ->set('bot.enabled', true)
        ->set('bot.provider', 'gemini')
        ->set('bot.api_key', 'gem-test-123')
        ->call('saveBot')
        ->assertHasNoErrors();

    $settings = app(BotSettings::class)->refresh();
    // A settings instance is resolved fresh from the store; re-read through a new one.
    $fresh = app()->make(BotSettings::class);
    expect($fresh->enabled)->toBeTrue()
        ->and($fresh->provider)->toBe('gemini')
        ->and($fresh->api_key)->toBe('gem-test-123');

    Livewire::actingAs($this->owner)
        ->test(SettingsPage::class)
        ->set('botKnowledgeTitle', 'Do you work in DHA Lahore?')
        ->set('botKnowledgeBody', 'Yes — Woodex Interior designs and installs across DHA Lahore.')
        ->call('addBotKnowledge')
        ->assertHasNoErrors();

    expect(BotKnowledge::query()->where('source', 'manual')->count())->toBe(1);
});
