<?php

use App\Domain\Conversations\Enums\MessageDirection;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Conversations\Enums\MessageType;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\Message;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Actions\SuggestQuoteAcceptance;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\QuoteEvent;
use App\Domain\Sales\Notifications\QuoteNotification;
use App\Domain\Sales\Services\QuoteEditor;
use App\Integrations\WhatsApp\Contracts\WhatsAppGateway;
use App\Integrations\WhatsApp\Fakes\FakeWhatsAppGateway;
use App\Livewire\Admin\Sales\QuoteBuilder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| D-112: a client's "ok" on WhatsApp raises its hand; only a person accepts a price.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->sales = $this->userWithRole('sales');
    $this->lead = Lead::factory()->create(['owner_id' => $this->sales->id, 'business_line' => 'interior']);
    $this->lead->contact->update(['phone' => '0322 4000768', 'whatsapp' => '0322 4000768', 'consent_whatsapp' => true]);
    $this->app->instance(WhatsAppGateway::class, new FakeWhatsAppGateway);
});

function quotedForHint(): Quote
{
    $quote = app(CreateQuote::class)->handle(test()->lead->contact, BusinessLine::Interior, test()->sales, test()->lead, null, 'Kitchen and wardrobes');
    $editor = app(QuoteEditor::class);
    $section = $quote->sections()->first() ?? $editor->addSection($quote, 'Kitchen');
    $editor->addLine($section, ['description' => 'Kitchen cabinets', 'unit' => 'rft', 'qty' => '10', 'rate_minor' => 2_500_000]);
    $quote->items()->where('rate_minor', 0)->each(fn ($item) => $editor->deleteLine($item));
    $quote = $quote->refresh();
    app(SendQuote::class)->handle($quote, test()->sales, 'link');

    return $quote->refresh();
}

function clientReply(string $body): Message
{
    $conversation = Conversation::query()->firstOrCreate(
        ['contact_id' => test()->lead->contact_id, 'channel' => 'whatsapp'],
        ['external_number' => '0322 4000768', 'status' => 'open', 'window_expires_at' => now()->addHour(), 'unread_count' => 0],
    );

    return Message::create([
        'conversation_id' => $conversation->id,
        'direction' => MessageDirection::In,
        'type' => MessageType::Text,
        'body' => $body,
        'status' => MessageStatus::Delivered,
    ]);
}

it('notices a reply that sounds like acceptance and tells the team, without accepting the quotation', function () {
    Notification::fake();
    $quote = quotedForHint();
    $message = clientReply('Ok');

    $flagged = app(SuggestQuoteAcceptance::class)->handle($message);

    expect($flagged?->id)->toBe($quote->id)
        ->and($quote->refresh()->status)->toBe(QuoteStatus::Sent)
        ->and($quote->acceptance_hint_at)->not->toBeNull()
        ->and($quote->acceptanceHintMessage->body)->toBe('Ok')
        ->and(QuoteEvent::query()->where('type', 'acceptance_suggested')->exists())->toBeTrue();

    Notification::assertSentTo($this->sales, QuoteNotification::class);
});

it('hears yes in the words clients here actually write', function () {
    $action = app(SuggestQuoteAcceptance::class);

    foreach (['Ok', 'okay', 'Yes', 'approved', 'go ahead', 'ji haan', 'theek hai', 'bilkul', '👍', 'Ok 👍'] as $reply) {
        expect($action->soundsLikeAcceptance($reply))->toBeTrue("{$reply} should sound like a yes");
    }
});

it('does not hear a yes in a reply that is a change request or a question', function () {
    $action = app(SuggestQuoteAcceptance::class);

    foreach ([
        'Ok but can we change the wardrobe to laminate instead?',
        'Yes, however I want to see one more option for the kitchen',
        'Can you send the drawing again?',
        'Ok, what is the delivery time for this?',
        'Thanks, I will discuss with my husband and let you know',
        '',
    ] as $reply) {
        expect($action->soundsLikeAcceptance($reply))->toBeFalse("\"{$reply}\" is not an acceptance");
    }
});

it('stays quiet when there is no sent quotation, when the reply predates it, and once it is flagged', function () {
    // A reply an hour before the quotation was sent is about something else entirely.
    $this->travel(-60)->minutes();
    $early = clientReply('Ok');
    $this->travelBack();

    expect(app(SuggestQuoteAcceptance::class)->handle($early))->toBeNull(); // nothing sent yet

    quotedForHint();

    expect(app(SuggestQuoteAcceptance::class)->handle($early))->toBeNull(); // the reply predates the quotation

    $later = clientReply('ok');
    app(SuggestQuoteAcceptance::class)->handle($later);

    expect(app(SuggestQuoteAcceptance::class)->handle($later))->toBeNull(); // already flagged, do not ask twice
});

it('lets the team accept on the strength of the reply, and says who confirmed it', function () {
    $quote = quotedForHint();
    app(SuggestQuoteAcceptance::class)->handle(clientReply('Theek hai'));

    Livewire\Livewire::actingAs($this->sales)
        ->test(QuoteBuilder::class, ['quote' => $quote])
        ->call('acceptFromHint')
        ->assertDispatched('toast');

    $quote->refresh();

    expect($quote->status)->toBe(QuoteStatus::Accepted)
        ->and($quote->accepted_at)->not->toBeNull()
        ->and(QuoteEvent::query()->where('type', 'accepted')->sole()->actor_user_id)->toBe($this->sales->id);
});

it('lets the team answer "not a yes", and the banner stops asking', function () {
    $quote = quotedForHint();
    app(SuggestQuoteAcceptance::class)->handle(clientReply('Ok'));

    Livewire\Livewire::actingAs($this->sales)
        ->test(QuoteBuilder::class, ['quote' => $quote])
        ->call('dismissAcceptanceHint')
        ->assertDispatched('toast');

    expect($quote->refresh()->acceptance_hint_at)->toBeNull()
        ->and($quote->status)->toBe(QuoteStatus::Sent)
        ->and(QuoteEvent::query()->where('type', 'acceptance_hint_dismissed')->exists())->toBeTrue();
});
