<?php

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Enums\StageType;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\ReviseQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\QuoteEvent;
use App\Domain\Sales\Notifications\QuoteNotification;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuoteLinks;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| The client's quotation page (QUO-009), view tracking (QUO-010) and acceptance (QUO-011, first part).
*/

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 24)->setTime(11, 0));
    Storage::fake('local');
    Notification::fake();
    $this->owner = $this->userWithRole('owner');
    $this->sales = $this->userWithRole('sales');
    $this->lead = Lead::factory()->create(['owner_id' => $this->sales->id, 'business_line' => 'interior']);

    $quote = app(CreateQuote::class)->handle($this->lead->contact, BusinessLine::Interior, $this->sales, $this->lead, null, 'Lounge');
    $editor = app(QuoteEditor::class);
    $section = $quote->sections()->first() ?? $editor->addSection($quote, 'Lounge');
    $editor->addLine($section, ['description' => 'Wall panelling, walnut veneer', 'unit' => 'sqft', 'qty' => '120', 'rate_minor' => 250_000]);
    $quote->items()->where('rate_minor', 0)->each(fn ($item) => $editor->deleteLine($item));
    app(SendQuote::class)->handle($quote->refresh(), $this->sales);
    $this->quote = $quote->refresh();
    $this->link = QuoteLinks::signed('quotes.portal', $this->quote);
});

const PHONE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

it('opens only with a valid signature', function () {
    $this->get('/q/'.$this->quote->public_id)->assertForbidden();
    $this->get($this->link.'x')->assertForbidden();
    $this->get($this->link)->assertOk()->assertSee($this->quote->label())->assertSee('Lounge')->assertSee('Rs 300,000');
});

it('expires 60 days after the validity date', function () {
    $this->travelTo($this->quote->valid_until->copy()->addDays(61));
    $this->get($this->link)->assertForbidden();
});

it('counts client views, alerts on the first view and on a hot quote, but not staff previews', function () {
    $this->withHeader('User-Agent', PHONE_UA)->get($this->link)->assertOk();
    $quote = $this->quote->refresh();

    expect($quote->status)->toBe(QuoteStatus::Viewed)
        ->and($quote->view_count)->toBe(1)
        ->and($quote->first_viewed_at)->not->toBeNull();
    $this->assertDatabaseHas('activities', ['subject_id' => $quote->id, 'subject_type' => 'quote']);
    expect(QuoteEvent::query()->where('quote_id', $quote->id)->where('type', 'viewed')->first()->data['device'])->toBe('phone · Safari');
    Notification::assertSentTo([$this->owner, $this->sales], QuoteNotification::class, fn ($n) => $n->kind === 'first_view');

    $this->get($this->link);
    $this->get($this->link);
    Notification::assertSentTo($this->owner, QuoteNotification::class, fn ($n) => $n->kind === 'hot');
    expect($this->quote->refresh()->view_count)->toBe(3);

    $this->actingAs($this->owner)->get($this->link)->assertOk();
    expect($this->quote->refresh()->view_count)->toBe(3);
});

it('downloads the frozen PDF', function () {
    $response = $this->get(QuoteLinks::signed('quotes.portal.pdf', $this->quote))->assertOk();
    $version = $this->quote->versions()->firstOrFail();

    expect(hash('sha256', $response->streamedContent()))->toBe($version->pdf_sha256);
    $this->assertDatabaseHas('quote_events', ['quote_id' => $this->quote->id, 'type' => 'downloaded']);
});

it('accepts with a typed name: quote accepted, contact becomes a client, lead won, team alerted', function () {
    $this->post(QuoteLinks::signed('quotes.portal.accept', $this->quote), ['revision' => $this->quote->revision, 'name' => 'Ayesha Khan', 'phone' => '0300 1234567'])
        ->assertRedirect();
    expect($this->quote->refresh()->status)->toBe(QuoteStatus::Sent); // the confirmation box is required

    $this->post(QuoteLinks::signed('quotes.portal.accept', $this->quote), ['revision' => $this->quote->revision, 'name' => 'Ayesha Khan', 'phone' => '0300 1234567', 'confirm' => '1'])
        ->assertRedirect();

    $quote = $this->quote->refresh();
    expect($quote->status)->toBe(QuoteStatus::Accepted)
        ->and($quote->accepted_name)->toBe('Ayesha Khan')
        ->and($quote->accepted_phone)->toBe('+923001234567')
        ->and($quote->contact->refresh()->is_client)->toBeTrue()
        ->and($this->lead->refresh()->stage->type)->toBe(StageType::Won);
    Notification::assertSentTo($this->owner, QuoteNotification::class, fn ($n) => $n->kind === 'accepted');

    $this->get($this->link)->assertOk()->assertSee('Your acceptance is confirmed')->assertDontSee('Accept this quotation');

    // A second acceptance is refused.
    $this->post(QuoteLinks::signed('quotes.portal.accept', $this->quote), ['revision' => $this->quote->revision, 'name' => 'Someone else', 'confirm' => '1']);
    expect($this->quote->refresh()->accepted_name)->toBe('Ayesha Khan');
});

it('takes change requests back to the team', function () {
    $this->post(QuoteLinks::signed('quotes.portal.changes', $this->quote), ['revision' => $this->quote->revision, 'comment' => 'Please use oak instead of walnut.'])->assertRedirect();

    $quote = $this->quote->refresh();
    expect($quote->status)->toBe(QuoteStatus::ChangesRequested)->and($quote->isEditable())->toBeTrue();
    $this->assertDatabaseHas('quote_events', ['quote_id' => $quote->id, 'type' => 'changes_requested']);
    Notification::assertSentTo($this->sales, QuoteNotification::class, fn ($n) => $n->kind === 'changes_requested');
    $this->get($this->link)->assertSee('We received your comments')->assertDontSee('Accept this quotation');
});

it('refuses acceptance after the validity date and while a new revision is prepared', function () {
    $this->travelTo($this->quote->valid_until->copy()->addDay());
    $this->post(QuoteLinks::signed('quotes.portal.accept', $this->quote), ['revision' => $this->quote->revision, 'name' => 'Ayesha Khan', 'confirm' => '1']);
    expect($this->quote->refresh()->status)->not->toBe(QuoteStatus::Accepted);
    $this->get(QuoteLinks::signed('quotes.portal', $this->quote))->assertSee('This quotation expired');

    $this->travelBack();
    $this->travelTo(now()->setDate(2026, 11, 25));
    app(ReviseQuote::class)->handle($this->quote->refresh(), $this->sales);
    $this->get(QuoteLinks::signed('quotes.portal', $this->quote))->assertOk()->assertSee('An updated quotation is being prepared')->assertDontSee('Accept this quotation');
});

it('always shows the latest sent revision on the same link', function () {
    app(ReviseQuote::class)->handle($this->quote->refresh(), $this->sales);
    app(QuoteEditor::class)->updateLine($this->quote->items()->first(), ['qty' => '100']);
    app(SendQuote::class)->handle($this->quote->refresh(), $this->sales);

    $this->get($this->link)->assertOk()->assertSee($this->quote->number.' R1')->assertSee('Rs 250,000')->assertDontSee('Rs 300,000');
});

it('builds a WhatsApp share message with the link', function () {
    $url = QuoteLinks::whatsappShare($this->quote);

    expect($url)->toStartWith('https://wa.me/')
        ->and(urldecode((string) $url))->toContain($this->quote->label())->toContain('/q/'.$this->quote->public_id);
})->skip(fn () => test()->lead->contact->whatsappNumber() === null, 'contact without WhatsApp number');
