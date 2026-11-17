<?php

declare(strict_types=1);

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Enums\StageType;
use App\Domain\Crm\Models\Lead;
use App\Domain\Projects\Models\Project;
use App\Domain\Sales\Actions\AcceptQuote;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\DeclineQuote;
use App\Domain\Sales\Actions\RecalculateQuote;
use App\Domain\Sales\Actions\ReviseQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Enums\AcceptanceChannel;
use App\Domain\Sales\Enums\MilestonePlanKey;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Exceptions\QuoteActionDenied;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\QuoteEvent;
use App\Domain\Sales\Services\QuoteEditor;
use App\Livewire\Admin\Sales\QuoteBuilder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| The client's answer, written down by a member of staff (list line T-080).
|
| A quotation that has been sent waits for the client. The client's own page records a yes itself, but that page is
| an optional module and is switched off; a WhatsApp reply can raise a hint. Before this, a client who said yes on
| the telephone — the ordinary case — left a quotation that could never become Accepted, and nothing in the code
| could set one to Declined at all.
*/

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    Mail::fake();
    $this->sales = $this->userWithRole('sales');
    $this->lead = Lead::factory()->create([
        'owner_id' => $this->sales->id, 'business_line' => 'furniture', 'service' => 'office-furniture',
        'message' => 'Twelve workstations for a new office.', 'created_at' => now()->subDay(),
    ]);
    $this->lead->contact->update(['name' => 'Ayesha Khan', 'phone' => '0322 4000768', 'whatsapp' => '0322 4000768']);
});

/** A Furniture quotation for the lead with one priced line; sent to the client unless told otherwise. */
function quotationForAnswer(bool $send = true): Quote
{
    $quote = app(CreateQuote::class)->handle(test()->lead->contact, BusinessLine::Furniture, test()->sales, test()->lead, null, 'Office furniture');
    app(QuoteEditor::class)->updateLine($quote->items()->firstOrFail(), [
        'product_name' => 'Executive workstation', 'description' => 'Laminate top on a powder-coated frame.',
        'unit' => 'nos', 'qty' => '12', 'rate_minor' => 4_500_000,
    ]);

    if ($send) {
        app(SendQuote::class)->handle($quote->refresh(), test()->sales, 'link', false);
    }

    return $quote->refresh();
}

it('offers the two answers on a quotation that is with the client, and on no other', function () {
    $draft = quotationForAnswer(send: false);

    Livewire::actingAs($this->sales)->test(QuoteBuilder::class, ['quote' => $draft])
        ->assertDontSee('Client accepted')
        ->assertDontSee('Client declined');

    $sent = quotationForAnswer();

    Livewire::actingAs($this->sales)->test(QuoteBuilder::class, ['quote' => $sent])
        ->assertSee('record the answer when it comes')
        ->assertSee('Client accepted')
        ->assertSee('Client declined');
});

it('records a yes that came by phone: who said it, how, and who wrote it down', function () {
    $quote = quotationForAnswer();

    Livewire::actingAs($this->sales)->test(QuoteBuilder::class, ['quote' => $quote])
        ->call('openAnswer', 'accepted')
        ->assertSet('showAnswer', true)
        ->assertSet('answerName', 'Ayesha Khan')
        ->assertSee('This cannot be undone')
        ->set('answerName', 'Bilal Khan (procurement)')
        ->set('answerChannel', 'phone')
        ->set('answerNote', 'Purchase order PO-7781 follows by e-mail.')
        ->call('recordAnswer')
        ->assertHasNoErrors()
        ->assertSet('showAnswer', false)
        ->assertDispatched('toast')
        ->assertSee('Accepted by phone by')
        ->assertSee('Bilal Khan (procurement)')
        ->assertSee('recorded by '.$this->sales->name)
        ->assertSee('Purchase order PO-7781 follows by e-mail.')
        // A sales person has no such button: the banner names who does the billing.
        ->assertSee('Not billed yet: Accounts bills this sale in full from here, with Create full invoice.')
        ->assertDontSee('Use Create full invoice to bill this Furniture sale.')
        ->assertDontSee('Client accepted');

    // The accountant, who holds the button, is told to use it.
    Livewire::actingAs($this->userWithRole('accountant'))->test(QuoteBuilder::class, ['quote' => $quote->refresh()])
        ->assertSee('Use Create full invoice to bill this Furniture sale.');

    $quote->refresh();
    $event = QuoteEvent::query()->where('quote_id', $quote->id)->where('type', 'accepted')->sole();

    expect($quote->status)->toBe(QuoteStatus::Accepted)
        ->and($quote->accepted_at)->not->toBeNull()
        ->and($quote->accepted_name)->toBe('Bilal Khan (procurement)')
        ->and($event->actor_user_id)->toBe($this->sales->id)
        ->and($event->channel)->toBe('phone')
        ->and($event->data['recorded_by_staff'])->toBeTrue()
        ->and($event->data['note'])->toBe('Purchase order PO-7781 follows by e-mail.')
        ->and($event->data['total_minor'])->toBe((int) $quote->total_minor);
});

it('does on a recorded yes what the client page does: the lead is won, the contact is a client, the project is open', function () {
    $quote = quotationForAnswer();

    Livewire::actingAs($this->sales)->test(QuoteBuilder::class, ['quote' => $quote])
        ->call('openAnswer', 'accepted')
        ->set('answerChannel', 'in_person')
        ->call('recordAnswer')
        ->assertHasNoErrors();

    $lead = $this->lead->refresh();

    expect($lead->won_at)->not->toBeNull()
        ->and($lead->stage->type)->toBe(StageType::Won)
        ->and($lead->contact->refresh()->is_client)->toBeTrue()
        ->and(Project::query()->where('quote_id', $quote->id)->count())->toBe(1)
        // A Furniture sale is billed in full, as a separate step: accepting raises no invoice.
        ->and(Invoice::query()->count())->toBe(0);

    $this->assertDatabaseHas('activities', ['lead_id' => $lead->id, 'subject_id' => $quote->id, 'user_id' => $this->sales->id]);
    expect((string) QuoteEvent::query()->where('type', 'accepted')->sole()->channel)->toBe('in_person');
});

it('raises the advance invoice on a recorded yes where the quotation has a stage due on acceptance', function () {
    // The Interior brand's quotations carry payment stages; Furniture's are billed in full as a separate step.
    $lead = Lead::factory()->create(['owner_id' => $this->sales->id, 'business_line' => 'interior', 'service' => 'kitchen', 'message' => 'A kitchen.', 'created_at' => now()->subDay()]);
    $lead->contact->update(['name' => 'Hina Qureshi', 'phone' => '0300 1234567', 'whatsapp' => '0300 1234567']);
    $quote = app(CreateQuote::class)->handle($lead->contact, BusinessLine::Interior, $this->sales, $lead, null, 'Kitchen');
    $editor = app(QuoteEditor::class);
    $editor->addLine($quote->sections()->first() ?? $editor->addSection($quote, 'Kitchen'), ['description' => 'Cabinets, lacquer finish', 'unit' => 'sqft', 'qty' => '1', 'rate_minor' => 100_000_000]);
    $quote->items()->where('rate_minor', 0)->each(fn ($item) => $editor->deleteLine($item));
    $editor->setMilestonePlan($quote->refresh(), MilestonePlanKey::Execution);
    app(RecalculateQuote::class)->handle($quote->refresh(), $this->sales);
    app(SendQuote::class)->handle($quote->refresh(), $this->sales);

    Livewire::actingAs($this->sales)->test(QuoteBuilder::class, ['quote' => $quote->refresh()])
        ->call('openAnswer', 'accepted')
        ->assertSee('A payment stage that falls due on acceptance is invoiced now.')
        ->set('answerChannel', 'email')
        ->call('recordAnswer')
        ->assertHasNoErrors()
        ->assertSee('Accepted by e-mail by')
        ->assertSee('The advance invoice was raised with the project.');

    $invoice = Invoice::query()->sole();
    $project = Project::query()->sole();
    $event = QuoteEvent::query()->where('quote_id', $quote->id)->where('type', 'invoiced')->sole();

    expect((int) $invoice->total_minor)->toBe(intdiv((int) $quote->refresh()->total_minor, 2))
        ->and($invoice->project_id)->toBe($project->id)
        // The event names the project it bills against (it used to drop it).
        ->and($event->data['project_id'])->toBe($project->id)
        ->and($event->data['invoice_id'])->toBe($invoice->id);
});

it('asks who accepted and refuses a way of answering it does not know', function () {
    $quote = quotationForAnswer();

    Livewire::actingAs($this->sales)->test(QuoteBuilder::class, ['quote' => $quote])
        ->call('openAnswer', 'accepted')
        ->set('answerName', '')
        ->call('recordAnswer')
        ->assertHasErrors('answerName')
        ->assertSee('Name the person who accepted.')
        ->set('answerName', 'Ayesha Khan')
        ->set('answerChannel', 'carrier_pigeon')
        ->call('recordAnswer')
        ->assertHasErrors('answerChannel');

    expect($quote->refresh()->status)->toBe(QuoteStatus::Sent);
});

it('records a no with the reason, leaves the lead alone, and the quotation can be revised', function () {
    $quote = quotationForAnswer();

    Livewire::actingAs($this->sales)->test(QuoteBuilder::class, ['quote' => $quote])
        ->call('openAnswer', 'declined')
        ->assertSet('answerKind', 'declined')
        ->call('recordAnswer')
        ->assertHasErrors('answerReason')
        ->assertSee('Give the reason the client gave, in a few words.')
        ->set('answerReason', 'Chose another supplier on delivery time.')
        ->call('recordAnswer')
        ->assertHasNoErrors()
        ->assertSet('showAnswer', false)
        ->assertSee('The client declined this quotation')
        ->assertSee('Chose another supplier on delivery time.')
        ->assertSee('recorded by '.$this->sales->name)
        ->assertDontSee('Client accepted');

    $quote->refresh();
    $event = QuoteEvent::query()->where('quote_id', $quote->id)->where('type', 'declined')->sole();
    $lead = $this->lead->refresh();

    expect($quote->status)->toBe(QuoteStatus::Declined)
        ->and($quote->declined_at)->not->toBeNull()
        ->and($quote->accepted_at)->toBeNull()
        ->and($event->actor_user_id)->toBe($this->sales->id)
        ->and($event->data['reason'])->toBe('Chose another supplier on delivery time.')
        ->and($lead->lost_at)->toBeNull()
        ->and($lead->won_at)->toBeNull()
        ->and(Project::query()->count())->toBe(0)
        ->and(in_array($quote->status, ReviseQuote::REVISABLE, true))->toBeTrue();

    $revised = app(ReviseQuote::class)->handle($quote, $this->sales);

    expect($revised->status)->toBe(QuoteStatus::Draft);
});

it('answers a WhatsApp hint when the client is recorded as having declined', function () {
    $quote = quotationForAnswer();
    $quote->update(['acceptance_hint_at' => now()]);

    app(DeclineQuote::class)->handle($quote, $this->sales, 'The project was cancelled.');

    expect($quote->refresh()->acceptance_hint_at)->toBeNull()
        ->and($quote->status)->toBe(QuoteStatus::Declined);
});

it('refuses an answer for a quotation that is not with the client', function () {
    $draft = quotationForAnswer(send: false);

    Livewire::actingAs($this->sales)->test(QuoteBuilder::class, ['quote' => $draft])
        ->call('openAnswer', 'accepted')
        ->call('recordAnswer')
        ->assertHasErrors('answer')
        ->assertSee('only a quotation that is with the client can be accepted');

    expect($draft->refresh()->status)->toBe(QuoteStatus::Draft)
        ->and(fn () => app(DeclineQuote::class)->handle($draft, $this->sales, 'No budget this year.'))
        ->toThrow(QuoteActionDenied::class, 'only a quotation that is with the client can be marked as declined');
});

it('refuses a yes on a quotation whose validity has passed, and says what to do', function () {
    $quote = quotationForAnswer();
    $quote->update(['valid_until' => today()->subDay()]);

    Livewire::actingAs($this->sales)->test(QuoteBuilder::class, ['quote' => $quote])
        ->call('openAnswer', 'accepted')
        ->call('recordAnswer')
        ->assertHasErrors('answer')
        ->assertSee('revise it and send it again before an acceptance is recorded');

    expect($quote->refresh()->status)->toBe(QuoteStatus::Sent);
});

it('does not accept twice', function () {
    $quote = quotationForAnswer();

    $screen = Livewire::actingAs($this->sales)->test(QuoteBuilder::class, ['quote' => $quote])
        ->call('openAnswer', 'accepted')
        ->call('recordAnswer')
        ->assertHasNoErrors();

    // A second press of the same form — a double click, or a second tab left open.
    $screen->set('showAnswer', true)->call('recordAnswer')->assertHasErrors('answer');

    expect(QuoteEvent::query()->where('type', 'accepted')->count())->toBe(1)
        ->and(Project::query()->count())->toBe(1);
});

it('keeps the answer from people who may read a quotation but not send one', function () {
    $quote = quotationForAnswer();
    $accountant = $this->userWithRole('accountant');

    Livewire::actingAs($accountant)->test(QuoteBuilder::class, ['quote' => $quote])
        ->assertDontSee('Client accepted')
        ->assertDontSee('Client declined')
        ->call('openAnswer', 'accepted')
        ->assertForbidden();

    Livewire::actingAs($accountant)->test(QuoteBuilder::class, ['quote' => $quote])
        ->set('answerName', 'Ayesha Khan')
        ->call('recordAnswer')
        ->assertForbidden();

    // The actions ask again themselves, whoever calls them.
    expect(fn () => app(DeclineQuote::class)->handle($quote, $accountant, 'Not this year.'))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(AcceptQuote::class)->handle($quote, 'Ayesha Khan', null, null, null, $accountant, null, AcceptanceChannel::Phone))
        ->toThrow(AuthorizationException::class);

    expect($quote->refresh()->status)->toBe(QuoteStatus::Sent);
});

it('names every way of answering in words a sentence can end with', function () {
    foreach (AcceptanceChannel::cases() as $channel) {
        expect($channel->label())->not->toBe('')
            ->and($channel->phrase())->toMatch('/^(by|in|on) /');
    }

    expect(AcceptanceChannel::options())->toHaveCount(5)
        ->and(AcceptanceChannel::WhatsApp->phrase())->toBe('on WhatsApp');
});
