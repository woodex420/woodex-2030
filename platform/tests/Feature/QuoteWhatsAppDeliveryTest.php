<?php

use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Conversations\Enums\MessageType;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\Message;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Actions\SendQuoteByWhatsApp;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\QuoteEvent;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuoteLinks;
use App\Integrations\WhatsApp\Contracts\WhatsAppGateway;
use App\Integrations\WhatsApp\Fakes\FakeWhatsAppGateway;
use App\Livewire\Admin\Sales\QuoteBuilder;
use Illuminate\Support\Facades\Storage;

/*
| QUO-008 (D-111): a quotation reaches the client on WhatsApp as a signed link.
|
| The quotation itself is already sent by SendQuote; these tests are about the message that carries it, about
| Meta's 24-hour rule, and about never losing a quotation because the network said no.
*/

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 24)->setTime(11, 0));
    Storage::fake('local');
    $this->sales = $this->userWithRole('sales');
    $this->lead = Lead::factory()->create(['owner_id' => $this->sales->id, 'business_line' => 'interior']);
    $this->lead->contact->update(['phone' => '0322 4000768', 'whatsapp' => '0322 4000768', 'consent_whatsapp' => true]);
    $this->gateway = new FakeWhatsAppGateway;
    $this->app->instance(WhatsAppGateway::class, $this->gateway);
});

function whatsappQuote(): Quote
{
    $quote = app(CreateQuote::class)->handle(test()->lead->contact, BusinessLine::Interior, test()->sales, test()->lead, null, 'Kitchen and wardrobes');
    $editor = app(QuoteEditor::class);
    $section = $quote->sections()->first() ?? $editor->addSection($quote, 'Kitchen');
    $editor->addLine($section, ['description' => 'Kitchen cabinets, lacquer finish', 'unit' => 'rft', 'qty' => '10', 'rate_minor' => 2_500_000]);
    $quote->items()->where('rate_minor', 0)->each(fn ($item) => $editor->deleteLine($item));

    return $quote->refresh();
}

it('carries a sent quotation to the client on whatsapp as a link', function () {
    $quote = whatsappQuote();
    $version = app(SendQuote::class)->handle($quote, $this->sales, 'link');

    $delivery = app(SendQuoteByWhatsApp::class)->handle($quote->refresh(), $version, $this->sales);

    expect($delivery->ok)->toBeTrue()
        ->and($delivery->byTemplate)->toBeFalse();

    // One outbound message, to the client's number, in the conversation the team can read.
    $message = Message::query()->sole();

    expect($this->gateway->sent[0]['to'])->toBe('0322 4000768')
        ->and($message->direction->value)->toBe('out')
        ->and($message->status)->toBe(MessageStatus::Sent)
        ->and($message->body)->toContain($quote->label())
        ->and($message->body)->toContain('Kitchen and wardrobes')
        ->and($message->body)->toContain(QuoteLinks::portal($quote->refresh()))
        ->and(Conversation::query()->sole()->contact_id)->toBe($quote->contact_id)
        ->and(QuoteEvent::query()->where('type', 'whatsapp_sent')->exists())->toBeTrue();
});

it('shows the client the total and how long the price stands', function () {
    $quote = whatsappQuote();

    expect(QuoteLinks::whatsappBody($quote))
        ->toContain('Rs')
        ->toContain($quote->valid_until->format('j M Y'))
        ->toContain(QuoteLinks::portal($quote));
});

it('uses an approved template once the 24-hour window has closed, because Meta allows nothing else', function () {
    $quote = whatsappQuote();
    Conversation::query()->create([
        'contact_id' => $quote->contact_id,
        'channel' => 'whatsapp',
        'external_number' => '0322 4000768',
        'status' => 'open',
        'window_expires_at' => now()->subHour(),
    ]);

    $version = app(SendQuote::class)->handle($quote, $this->sales, 'link');
    $delivery = app(SendQuoteByWhatsApp::class)->handle($quote->refresh(), $version, $this->sales);

    expect($delivery->ok)->toBeTrue()
        ->and($delivery->byTemplate)->toBeTrue()
        ->and($this->gateway->sent[0]['template'])->toBe('quotation')
        ->and($this->gateway->sent[0]['components'][0]['parameters'][0]['text'])->toContain('/q/')
        ->and(Message::query()->sole()->type)->toBe(MessageType::Template);
});

it('keeps the quotation sent when whatsapp refuses the message, and says so on the timeline', function () {
    $this->gateway->fails('Meta refused the message.');
    $quote = whatsappQuote();

    $version = app(SendQuote::class)->handle($quote, $this->sales, 'link');
    $delivery = app(SendQuoteByWhatsApp::class)->handle($quote->refresh(), $version, $this->sales);

    expect($delivery->ok)->toBeFalse()
        ->and($delivery->error)->toBe('Meta refused the message.')
        ->and($quote->refresh()->status)->toBe(QuoteStatus::Sent)
        ->and(Message::query()->sole()->status)->toBe(MessageStatus::Failed);

    $event = QuoteEvent::query()->where('type', 'whatsapp_failed')->sole();

    expect($event->data['error'])->toBe('Meta refused the message.');

    // The team is told what to do instead of being left to assume the client has it.
    expect($delivery->summary($quote->label()))->toContain('could not be sent on WhatsApp')
        ->and($delivery->summary($quote->label()))->toContain('Meta refused the message.');
});

it('does not pretend to send when the client has no number or whatsapp is not connected', function () {
    $quote = whatsappQuote();
    $quote->contact->update(['phone' => null, 'whatsapp' => null]);

    $version = app(SendQuote::class)->handle($quote->refresh(), $this->sales, 'link');
    $delivery = app(SendQuoteByWhatsApp::class)->handle($quote->refresh(), $version, $this->sales);

    expect($delivery->ok)->toBeFalse()
        ->and($delivery->error)->toContain('no phone number')
        ->and($this->gateway->sent)->toBe([]);

    $this->gateway->configured(false);
    $quote->contact->update(['phone' => '0322 4000768', 'whatsapp' => '0322 4000768']);

    $delivery = app(SendQuoteByWhatsApp::class)->handle($quote->refresh(), $version, $this->sales);

    expect($delivery->ok)->toBeFalse()
        ->and($delivery->error)->toContain('not connected')
        ->and($this->gateway->sent)->toBe([]);
});

it('offers whatsapp in the send dialog only when the client can actually be reached', function () {
    $quote = whatsappQuote();

    Livewire\Livewire::actingAs($this->sales)
        ->test(QuoteBuilder::class, ['quote' => $quote])
        ->call('openSend')
        ->assertSet('sendWhatsapp', true)
        ->set('sendWhatsapp', true)
        ->call('send')
        ->assertDispatched('toast');

    expect($this->gateway->sent)->toHaveCount(1)
        ->and(QuoteEvent::query()->where('type', 'whatsapp_sent')->exists())->toBeTrue();
});

it('does not offer whatsapp for a client with no number', function () {
    $quote = whatsappQuote();
    $quote->contact->update(['phone' => null, 'whatsapp' => null]);

    Livewire\Livewire::actingAs($this->sales)
        ->test(QuoteBuilder::class, ['quote' => $quote])
        ->call('openSend')
        ->assertSet('sendWhatsapp', false);
});

it('records the delivery against the version that was sent, so a revision is not confused with the first one', function () {
    $quote = whatsappQuote();
    $version = app(SendQuote::class)->handle($quote, $this->sales, 'link');

    app(SendQuoteByWhatsApp::class)->handle($quote->refresh(), $version, $this->sales);

    $event = QuoteEvent::query()->where('type', 'whatsapp_sent')->sole();

    expect($event->quote_version_id)->toBe($version->id)
        ->and($event->data['revision'])->toBe((int) $version->revision);
});
