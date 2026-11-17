<?php

declare(strict_types=1);

use App\Domain\Conversations\Models\CannedReply;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Lead;
use App\Domain\Projects\Models\Project;
use App\Domain\Sales\Actions\AcceptQuote;
use App\Domain\Sales\Actions\CreateFurnitureInvoice;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Actions\SendQuoteByWhatsApp;
use App\Domain\Sales\Enums\AcceptanceChannel;
use App\Domain\Sales\Mail\QuoteSentMail;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\QuoteEvent;
use App\Domain\Sales\Services\InvoiceLinks;
use App\Domain\Sales\Services\QuoteEditor;
use App\Domain\Sales\Services\QuoteLinks;
use App\Integrations\WhatsApp\Contracts\WhatsAppGateway;
use App\Integrations\WhatsApp\Fakes\FakeWhatsAppGateway;
use App\Livewire\Admin\Projects\ProjectShow;
use App\Livewire\Admin\Sales\InvoiceShow;
use App\Livewire\Admin\Sales\QuoteBuilder;
use App\Settings\BusinessSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| The client pages are an optional module, and the product ships with it switched off (config/modules.php). Their
| addresses then answer 404. Pressing the sale through in a browser (list line T-080) showed what the admin did
| about that: after "Send now" it opened an empty dialog called "Share", told the sales person to "share the link
| with the client", wrote a dead link into the WhatsApp message and the e-mail, and showed one on every invoice.
|
| One question now has one answer — QuoteLinks::clientPage(), InvoiceLinks::clientPage() — and everything that puts
| a link in front of a person asks it. With no page, the PDF is the document and the screens say so.
*/

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    Mail::fake();
    // The product as it is shipped: Furniture alone, and no optional module but the enquiry endpoint.
    config(['modules.enabled' => 'lead_api', 'woodex.business_lines' => ['furniture']]);
    $this->owner = $this->userWithRole('owner');
    $this->lead = Lead::factory()->create([
        'owner_id' => $this->owner->id, 'business_line' => 'furniture', 'service' => 'office-furniture',
        'message' => 'Twelve workstations for a new office.', 'created_at' => now()->subDay(),
    ]);
    $this->lead->contact->update(['name' => 'Ayesha Khan', 'phone' => '0322 4000768', 'whatsapp' => '0322 4000768', 'email' => 'ayesha@example.test']);
});

function quotationWithoutClientPage(bool $send = true): Quote
{
    $quote = app(CreateQuote::class)->handle(test()->lead->contact, BusinessLine::Furniture, test()->owner, test()->lead, null, 'Office furniture');
    app(QuoteEditor::class)->updateLine($quote->items()->firstOrFail(), [
        'product_name' => 'Executive workstation', 'description' => 'Laminate top on a powder-coated frame.',
        'unit' => 'nos', 'qty' => '12', 'rate_minor' => 4_500_000,
    ]);

    if ($send) {
        app(SendQuote::class)->handle($quote->refresh(), test()->owner, 'link', false);
    }

    return $quote->refresh();
}

/**
 * Addresses of client pages (/q/…, /i/…, /p/…) found anywhere in a piece of HTML or text. Looked for by the public
 * ids of the records that exist — a pattern would also find "/p/…" inside an embedded image or font.
 *
 * @return list<string>
 */
function clientPageAddresses(string $text): array
{
    $text = html_entity_decode($text);
    $found = [];

    foreach (['q' => 'quotes', 'i' => 'invoices', 'p' => 'projects'] as $prefix => $table) {
        foreach (DB::table($table)->pluck('public_id') as $id) {
            if (str_contains($text, "/{$prefix}/{$id}")) {
                $found[] = "/{$prefix}/{$id}";
            }
        }
    }

    return $found;
}

it('gives a link to a client page only while the client pages are switched on', function () {
    $quote = quotationWithoutClientPage();
    $invoice = app(CreateFurnitureInvoice::class)->handle($quote, $this->owner);

    expect(QuoteLinks::clientPagesOpen())->toBeFalse()
        ->and(QuoteLinks::clientPage($quote))->toBeNull()
        ->and(InvoiceLinks::clientPage($invoice))->toBeNull();

    config(['modules.enabled' => 'lead_api,client_portal']);

    expect(QuoteLinks::clientPage($quote))->toContain('/q/'.$quote->public_id)
        ->and(InvoiceLinks::clientPage($invoice))->toContain('/i/'.$invoice->public_id);
});

it('writes a WhatsApp message that promises the PDF, not a page that does not open', function () {
    $quote = quotationWithoutClientPage();

    $body = QuoteLinks::whatsappBody($quote);

    expect(clientPageAddresses($body))->toBe([])
        ->and($body)->not->toContain('http')
        ->and($body)->toContain('The PDF comes with this message.')
        ->and($body)->toContain($quote->label())
        ->and(clientPageAddresses(urldecode((string) QuoteLinks::whatsappShare($quote))))->toBe([]);

    config(['modules.enabled' => 'lead_api,client_portal']);

    expect(clientPageAddresses(QuoteLinks::whatsappBody($quote)))->toHaveCount(1)
        ->and(QuoteLinks::whatsappBody($quote))->toContain('download the PDF and accept');
});

it('says before sending that a PDF is frozen, and after it what to do with that PDF', function () {
    $quote = quotationWithoutClientPage(send: false);

    $screen = Livewire::actingAs($this->owner)->test(QuoteBuilder::class, ['quote' => $quote])
        ->call('openSend')
        ->assertSee('A frozen copy (PDF) is made now')
        ->assertDontSee('PDF + client page')
        ->assertSee('there is no link while the client pages are switched off')
        ->assertSee('Nothing reaches the client by itself unless the e-mail is ticked.')
        ->call('send')
        ->assertHasNoErrors()
        ->assertDispatched('toast')
        ->assertSee('to the client')                       // the dialog: "Give WF-… to the client"
        ->assertSee('the PDF is what the client gets')
        ->assertSee('Download the PDF')
        ->assertSee('Open WhatsApp')
        ->assertSee('record it on this quotation')
        ->assertDontSee('Client page link')
        ->assertDontSee('Share the link');

    expect(clientPageAddresses($screen->html()))->toBe([]);

    // The dialog can be opened again later: the header offers it in place of "Share link".
    Livewire::actingAs($this->owner)->test(QuoteBuilder::class, ['quote' => $quote->refresh()])
        ->assertSee('Give to client')
        ->assertDontSee('Share link');
});

it('says on the timeline how the quotation went out', function () {
    $byHand = quotationWithoutClientPage();
    $byEmail = quotationWithoutClientPage(send: false);
    app(SendQuote::class)->handle($byEmail, $this->owner, 'link', true);

    $this->assertDatabaseHas('activities', ['subject_id' => $byHand->id, 'body' => "{$byHand->label()} sent to Ayesha Khan (PDF, to be given to the client)."]);
    $this->assertDatabaseHas('activities', ['subject_id' => $byEmail->id, 'body' => "{$byEmail->label()} sent to Ayesha Khan (PDF by e-mail)."]);

    config(['modules.enabled' => 'lead_api,client_portal']);
    $withPage = quotationWithoutClientPage();

    $this->assertDatabaseHas('activities', ['subject_id' => $withPage->id, 'body' => "{$withPage->label()} sent to Ayesha Khan (link)."]);
});

it('sends an e-mail with the PDF attached and no button to a page that does not open', function () {
    $quote = quotationWithoutClientPage();
    $version = $quote->versions()->firstOrFail();

    $mail = new QuoteSentMail($quote->id, $version->id);
    $mail->assertSeeInHtml('The PDF is attached. To confirm the order, reply to this email.')
        ->assertDontSeeInHtml('View your quotation')
        ->assertDontSeeInHtml('accept it online');

    expect(clientPageAddresses($mail->render()))->toBe([])
        ->and($mail->attachments())->toHaveCount(1);

    config(['modules.enabled' => 'lead_api,client_portal']);

    (new QuoteSentMail($quote->id, $version->id))->assertSeeInHtml('View your quotation')->assertSeeInHtml('/q/'.$quote->public_id);
});

it('does not send the link through the WhatsApp API when there is none, and says why', function () {
    $gateway = new FakeWhatsAppGateway;
    $this->app->instance(WhatsAppGateway::class, $gateway);
    $quote = quotationWithoutClientPage();

    $delivery = app(SendQuoteByWhatsApp::class)->handle($quote, $quote->versions()->firstOrFail(), $this->owner);

    expect($delivery->ok)->toBeFalse()
        ->and($delivery->error)->toContain('the client pages are switched off')
        ->and($gateway->sent)->toBe([]);

    // And the box on the send dialog is not offered at all.
    expect(Livewire::actingAs($this->owner)->test(QuoteBuilder::class, ['quote' => $quote])->instance()->whatsappReady())->toBeFalse();
});

it('leaves the link out of a canned reply rather than writing one that does not open', function () {
    $quote = quotationWithoutClientPage();
    $conversation = Conversation::query()->create([
        'contact_id' => $quote->contact_id, 'channel' => 'whatsapp', 'external_number' => '0322 4000768',
        'status' => 'open', 'window_expires_at' => now()->addHour(), 'unread_count' => 0,
    ]);
    $reply = new CannedReply(['title' => 'Quotation', 'body' => 'Your quotation: {quote_link}']);

    expect($reply->renderFor($conversation, app(BusinessSettings::class)))->toBe('Your quotation: ');

    config(['modules.enabled' => 'lead_api,client_portal']);

    expect($reply->renderFor($conversation, app(BusinessSettings::class)))->toContain('/q/'.$quote->public_id);
});

it('shows no client link on an invoice, and a WhatsApp message without one', function () {
    $quote = quotationWithoutClientPage();
    $invoice = app(CreateFurnitureInvoice::class)->handle($quote, $this->owner);

    // A draft goes to nobody, whatever the modules: it has no PDF, and its client page answers 404.
    config(['modules.enabled' => '*']);
    Livewire::actingAs($this->owner)->test(InvoiceShow::class, ['invoice' => $invoice])
        ->assertDontSee('Client link')
        ->assertDontSee('Send on WhatsApp');
    config(['modules.enabled' => 'lead_api']);

    app(IssueInvoice::class)->handle($invoice, $this->owner);

    $screen = Livewire::actingAs($this->owner)->test(InvoiceShow::class, ['invoice' => $invoice->refresh()])
        ->assertDontSee('Client link')
        ->assertSee('The client pages are switched off on this site: give the client the PDF.')
        ->assertSee('Send on WhatsApp');

    expect(clientPageAddresses($screen->html()))->toBe([])
        ->and(clientPageAddresses(urldecode((string) InvoiceLinks::whatsappShare($invoice))))->toBe([])
        ->and(urldecode((string) InvoiceLinks::whatsappShare($invoice)))->toContain('The PDF comes with this message.');

    // The quotation lists its invoices: no client link there either.
    expect(clientPageAddresses(Livewire::actingAs($this->owner)->test(QuoteBuilder::class, ['quote' => $quote->refresh()])->html()))->toBe([]);

    config(['modules.enabled' => 'lead_api,client_portal']);

    Livewire::actingAs($this->owner)->test(InvoiceShow::class, ['invoice' => $invoice])->assertSee('Client link');
});

it('offers no client link on the project a sale opens, and sends none by e-mail', function () {
    $quote = quotationWithoutClientPage();
    app(AcceptQuote::class)->handle($quote, 'Ayesha Khan', null, null, null, $this->owner, null, AcceptanceChannel::Phone);
    $project = Project::query()->where('quote_id', $quote->id)->sole();

    $screen = Livewire::actingAs($this->owner)->test(ProjectShow::class, ['project' => $project])
        ->assertSee('The client pages are switched off on this site, so this project has no client link to share.')
        ->assertDontSee('Email the link')
        ->call('sendPortalLink')
        ->assertDispatched('toast');

    expect(clientPageAddresses($screen->html()))->toBe([]);
    Mail::assertNothingOutgoing();

    config(['modules.enabled' => 'lead_api,client_portal']);

    Livewire::actingAs($this->owner)->test(ProjectShow::class, ['project' => $project])->assertSee('Email the link');
});

it('records the sale end to end with no client page anywhere: sent, accepted by phone, billed', function () {
    $quote = quotationWithoutClientPage(send: false);

    Livewire::actingAs($this->owner)->test(QuoteBuilder::class, ['quote' => $quote])
        ->call('openSend')
        ->call('send')
        ->assertHasNoErrors()
        ->set('showSend', false)
        ->assertSee('record the answer when it comes')
        ->call('openAnswer', 'accepted')
        ->set('answerChannel', 'signed_copy')
        ->call('recordAnswer')
        ->assertHasNoErrors()
        ->assertSee('Accepted on a signed copy by')
        ->call('createFullInvoice')
        ->assertRedirect();

    expect($quote->refresh()->status->value)->toBe('accepted')
        ->and(QuoteEvent::query()->where('quote_id', $quote->id)->pluck('type')->all())->toContain('sent', 'accepted')
        ->and($quote->invoices()->count())->toBe(1);
});
