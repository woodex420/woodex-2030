<?php

declare(strict_types=1);

use App\Domain\Crm\Actions\CaptureLead;
use App\Domain\Crm\Data\LeadInput;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Crm\Models\Contact;
use App\Domain\Sales\Actions\AllocatePayment;
use App\Domain\Sales\Actions\CreateManualInvoice;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\SendQuote;
use App\Domain\Sales\Events\InvoicePaid;
use App\Domain\Sales\Events\QuoteSent;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Services\QuoteEditor;
use App\Integrations\Support\PublicHostResolver;
use App\Integrations\Support\PublicUrlGuard;
use App\Integrations\Webhooks\DeliverWebhook;
use App\Integrations\Webhooks\OutboundWebhook;
use App\Integrations\Webhooks\WebhookDelivery;
use App\Integrations\Webhooks\WebhookDispatcher;
use App\Livewire\Admin\System\Integrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
    $this->actingAs($this->owner);
    Queue::fake();
    $this->app->instance(PublicHostResolver::class, new class extends PublicHostResolver
    {
        public function addresses(string $host): array
        {
            return ['8.8.8.8'];
        }
    });
});

function outboundEndpoint(array $overrides = []): OutboundWebhook
{
    return OutboundWebhook::query()->create(array_merge([
        'name' => 'Studio automation', 'url' => 'https://hooks.example.com/catch',
        'secret' => OutboundWebhook::generateSecret(), 'events' => ['lead.created'], 'is_active' => true,
    ], $overrides));
}

it('creates an encrypted signing secret shown once, not in model JSON', function () {
    $screen = Livewire::test(Integrations::class)->call('openWebhooks')
        ->set('webhookName', 'Zapier')->set('webhookUrl', 'https://hooks.example.com/catch')
        ->call('createWebhook')->assertHasNoErrors();
    $endpoint = OutboundWebhook::query()->sole();
    $secret = $screen->get('newWebhookSecret');
    expect($secret)->toStartWith('whsec_')
        ->and($endpoint->secret)->toBe($secret)
        ->and($endpoint->toArray())->not->toHaveKey('secret')
        ->and(DB::table('outbound_webhooks')->value('secret'))->not->toContain($secret);
    $screen->assertSee($secret)->call('$refresh')->assertSet('newWebhookSecret', null)->assertDontSee($secret);
});

it('keeps mutations owner-only while integration managers can inspect and test', function () {
    $endpoint = outboundEndpoint();
    $this->actingAs($this->userWithRole('admin'));
    Livewire::test(Integrations::class)->call('openWebhooks')->assertSee('Studio automation')
        ->call('toggleWebhook', $endpoint->id)->assertForbidden();
    Livewire::test(Integrations::class)->call('deleteWebhook', $endpoint->id)->assertForbidden();
    Livewire::test(Integrations::class)->call('rotateWebhookSecret', $endpoint->id)->assertForbidden();
});

it('rejects unsafe endpoints and invalid events at creation', function (string $url) {
    Livewire::test(Integrations::class)->set('webhookName', 'Bad endpoint')->set('webhookUrl', $url)
        ->call('createWebhook')->assertHasErrors('webhookUrl');
    expect(OutboundWebhook::query()->count())->toBe(0);
})->with(['http://hooks.example.com', 'https://127.0.0.1/', 'https://10.0.0.2/', 'https://[::1]/', 'https://app.localhost/', 'https://user:pass@example.com/', 'https://example.com:8080/']);

it('queues subscribed events on the database connection rather than calling during a business save', function () {
    $endpoint = outboundEndpoint();
    outboundEndpoint(['name' => 'Paused', 'is_active' => false]);
    outboundEndpoint(['name' => 'Invoice only', 'events' => ['invoice.paid']]);
    app(WebhookDispatcher::class)->dispatch('lead.created', ['ref' => 'L-TEST']);
    expect(WebhookDelivery::query()->count())->toBe(1)
        ->and(WebhookDelivery::query()->sole()->outbound_webhook_id)->toBe($endpoint->id);
    Queue::assertPushed(DeliverWebhook::class, fn ($job) => $job->connection === 'database' && $job->queue === 'webhooks' && $job->afterCommit === true);
    Http::assertNothingSent();
});

it('signs the exact POST body and records a successful delivery once', function () {
    Http::fake(['hooks.example.com/*' => Http::response('', 204)]);
    $endpoint = outboundEndpoint();
    $delivery = app(WebhookDispatcher::class)->ping($endpoint);
    $job = new DeliverWebhook($delivery->id);
    $job->handle();
    $job->handle();
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->hasHeader('X-Woodex-Delivery', (string) $delivery->id)
        && $request->hasHeader('X-Woodex-Signature', 'sha256='.hash_hmac('sha256', $request->body(), $endpoint->secret))
        && $request['event'] === 'webhook.test');
    expect($delivery->refresh()->status)->toBe('sent')->and($delivery->attempts)->toBe(1)
        ->and($delivery->response_status)->toBe(204);
});

it('records failures, retries with a stable delivery ID, and marks exhausted jobs failed', function () {
    Http::fake(['hooks.example.com/*' => Http::sequence()->push('', 503)->push('', 200)->push('', 500)]);
    $delivery = app(WebhookDispatcher::class)->ping(outboundEndpoint());
    $job = new DeliverWebhook($delivery->id);
    expect(fn () => $job->handle())->toThrow(RuntimeException::class);
    expect($delivery->refresh()->error)->toContain('503')->and($delivery->attempts)->toBe(1);
    $job->handle();
    expect($delivery->refresh()->status)->toBe('sent')->and($delivery->attempts)->toBe(2);
    $failure = app(WebhookDispatcher::class)->ping(outboundEndpoint());
    $failedJob = new DeliverWebhook($failure->id);
    expect(fn () => $failedJob->handle())->toThrow(RuntimeException::class);
    $failedJob->failed(new RuntimeException('exhausted'));
    expect($failure->refresh()->status)->toBe('failed')->and($failure->response_status)->toBe(500);
});

it('never calls removed or paused endpoints, even for already queued jobs', function () {
    Http::fake();
    $endpoint = outboundEndpoint();
    $delivery = app(WebhookDispatcher::class)->ping($endpoint);
    $endpoint->update(['is_active' => false]);
    (new DeliverWebhook($delivery->id))->handle();
    expect($delivery->refresh()->status)->toBe('failed');
    $endpoint->delete();
    (new DeliverWebhook($delivery->id))->handle();
    Http::assertNothingSent();
});

it('rechecks DNS before delivery and refuses private resolved addresses', function () {
    Http::fake();
    $delivery = app(WebhookDispatcher::class)->ping(outboundEndpoint());
    $this->app->instance(PublicHostResolver::class, new class extends PublicHostResolver
    {
        public function addresses(string $host): array
        {
            return ['127.0.0.1'];
        }
    });
    expect(fn () => (new DeliverWebhook($delivery->id))->handle())->toThrow(RuntimeException::class);
    Http::assertNothingSent();
    expect($delivery->refresh()->error)->toContain('public-address guard');
});

it('pins the verified public destination and disables proxies for arbitrary endpoints', function () {
    $options = PublicUrlGuard::requestOptions('https://hooks.example.com/catch');
    expect($options['curl'][CURLOPT_RESOLVE])->toBe(['hooks.example.com:443:8.8.8.8'])
        ->and($options['curl'][CURLOPT_PROXY])->toBe('');
});

it('queues a webhook for an actual new enquiry but not a returning one', function () {
    outboundEndpoint();
    app(CaptureLead::class)->handle(new LeadInput(name: 'Webhook client', phone: '03215550101', email: null, source: LeadSource::PhoneCall));
    expect(WebhookDelivery::query()->count())->toBe(1);
    app(CaptureLead::class)->handle(new LeadInput(name: 'Webhook client', phone: '03215550101', email: null, source: LeadSource::PhoneCall));
    expect(WebhookDelivery::query()->count())->toBe(1);
});

it('emits quote sent only after the version is frozen', function () {
    Event::fake([QuoteSent::class]);
    $quote = app(CreateQuote::class)->handle(Contact::factory()->create(), BusinessLine::Interior, $this->owner);
    app(QuoteEditor::class)->updateLine($quote->items()->firstOrFail(), ['description' => 'Joinery', 'rate_minor' => 100000]);
    $version = app(SendQuote::class)->handle($quote->refresh(), $this->owner);
    Event::assertDispatched(QuoteSent::class, fn ($event) => $event->version->id === $version->id && $event->quote->status->value === 'sent');
});

it('emits invoice paid only when an allocation reaches zero balance', function () {
    Event::fake([InvoicePaid::class]);
    $contact = Contact::factory()->create();
    $invoice = app(CreateManualInvoice::class)->handle($this->owner, [
        'contact_id' => $contact->id, 'business_line' => 'interior', 'issue_date' => today()->toDateString(),
        'due_date' => today()->toDateString(), 'items' => [['description' => 'Joinery', 'unit' => 'job', 'qty' => '1', 'rate_minor' => 10000]],
    ]);
    $invoice->update(['status' => 'issued']);
    $payment = Payment::query()->create(['contact_id' => $contact->id, 'invoice_id' => $invoice->id,
        'amount_minor' => 4000, 'status' => 'verified', 'method' => 'cash', 'received_on' => today()]);
    app(AllocatePayment::class)->handle($payment);
    Event::assertNotDispatched(InvoicePaid::class);
    $rest = Payment::query()->create(['contact_id' => $contact->id, 'invoice_id' => $invoice->id,
        'amount_minor' => 6000, 'status' => 'verified', 'method' => 'cash', 'received_on' => today()]);
    app(AllocatePayment::class)->handle($rest);
    Event::assertDispatchedTimes(InvoicePaid::class, 1);
});

it('allows pause resume and secret rotation without exposing the old secret', function () {
    $endpoint = outboundEndpoint();
    $oldSecret = $endpoint->secret;
    $screen = Livewire::test(Integrations::class)->call('openWebhooks')
        ->call('toggleWebhook', $endpoint->id);
    expect($endpoint->refresh()->is_active)->toBeFalse();
    $screen->call('toggleWebhook', $endpoint->id)->call('rotateWebhookSecret', $endpoint->id);
    expect($endpoint->refresh()->is_active)->toBeTrue()
        ->and($endpoint->secret)->not->toBe($oldSecret);
    $screen->assertDontSee($oldSecret)->call('deleteWebhook', $endpoint->id);
    expect(OutboundWebhook::query()->count())->toBe(0);
});

it('refuses unknown business-event subscriptions', function () {
    Livewire::test(Integrations::class)->set('webhookName', 'Unknown event')
        ->set('webhookUrl', 'https://hooks.example.com/catch')->set('webhookEvents', ['client.password'])
        ->call('createWebhook')->assertHasErrors('webhookEvents.0');
    expect(OutboundWebhook::query()->count())->toBe(0);
});
