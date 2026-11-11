<?php

use App\Domain\Conversations\Actions\QueueWhatsAppEvent;
use App\Domain\Conversations\Actions\RecordMessageStatus;
use App\Domain\Conversations\Enums\MessageDirection;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Conversations\Jobs\ProcessWhatsAppEvent;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\Message;
use App\Domain\Crm\Models\Contact;
use App\Domain\System\Enums\WebhookStatus;
use App\Domain\System\Models\WebhookEvent;
use App\Integrations\WhatsApp\Contracts\WhatsAppGateway;
use App\Integrations\WhatsApp\Fakes\FakeWhatsAppGateway;
use Illuminate\Contracts\Queue\Factory;
use Illuminate\Queue\Connectors\DatabaseConnector;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['services.whatsapp.app_secret' => 'recovery-secret', 'services.whatsapp.phone_number_id' => 'TEST-NUMBER']);
    Queue::fake();
    Http::preventStrayRequests();
    Storage::fake('local');
    $this->app->instance(WhatsAppGateway::class, new FakeWhatsAppGateway);
});

function recoveryValue(string $id = 'wamid.RECOVERY', string $from = '923000000001', string $name = 'Test client'): array
{
    return [
        'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => 'TEST-NUMBER'],
        'contacts' => [['wa_id' => $from, 'profile' => ['name' => $name]]],
        'messages' => [['id' => $id, 'from' => $from, 'type' => 'text', 'timestamp' => (string) now()->timestamp, 'text' => ['body' => 'Test enquiry']]],
    ];
}

function recoveryPayload(array $value): array
{
    return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'TEST-WABA', 'changes' => [['field' => 'messages', 'value' => $value]]]]];
}

function recoveryPost(array $payload, string $secret = 'recovery-secret'): TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    return test()->call('POST', '/webhooks/whatsapp', [], [], [], [
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

function processRecoveryEvent(WebhookEvent $event): void
{
    app()->call([new ProcessWhatsAppEvent($event->id), 'handle']);
}

it('refuses untrusted intake before storing or dispatching anything', function () {
    recoveryPost(recoveryPayload(recoveryValue()), 'wrong-secret')->assertForbidden();
    expect(WebhookEvent::query()->count())->toBe(0)->and(Message::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('persists every entry change message and status before any provider or business work', function () {
    $outA = Message::factory()->outbound()->create(['external_id' => 'wamid.OUT-A']);
    $outB = Message::factory()->outbound()->create(['external_id' => 'wamid.OUT-B']);
    $first = recoveryValue('wamid.IN-A', '923000000001', 'Test A');
    $second = recoveryValue('wamid.IN-B', '923000000002', 'Test B');
    $first['messages'][] = $second['messages'][0];
    $first['contacts'][] = $second['contacts'][0];
    $first['statuses'] = [['id' => 'wamid.OUT-A', 'status' => 'delivered'], ['id' => 'wamid.OUT-B', 'status' => 'read']];
    $payload = recoveryPayload($first);
    $payload['entry'][0]['changes'][] = ['field' => 'messages', 'value' => recoveryValue('wamid.IN-C', '923000000003', 'Test C')];
    $payload['entry'][] = ['id' => 'TEST-WABA', 'changes' => [['value' => recoveryValue('wamid.IN-D', '923000000004', 'Test D')]]];
    $contactCount = Contact::query()->count();

    recoveryPost($payload)->assertOk();
    expect(WebhookEvent::query()->count())->toBe(6)
        ->and(WebhookEvent::query()->where('status', WebhookStatus::Received)->count())->toBe(6)
        ->and(Message::query()->count())->toBe(2)->and(Contact::query()->count())->toBe($contactCount);
    Queue::assertPushed(ProcessWhatsAppEvent::class, 6);
    Http::assertNothingSent();

    foreach (WebhookEvent::query()->orderBy('id')->get() as $event) {
        processRecoveryEvent($event);
        processRecoveryEvent($event); // duplicate worker execution must also be safe
    }
    expect(Message::query()->where('direction', MessageDirection::In)->count())->toBe(4)
        ->and($outA->refresh()->status)->toBe(MessageStatus::Delivered)
        ->and($outB->refresh()->status)->toBe(MessageStatus::Read)
        ->and(WebhookEvent::query()->where('status', WebhookStatus::Processed)->count())->toBe(6);
    foreach (['A', 'B', 'C', 'D'] as $letter) {
        $message = Message::query()->where('external_id', 'wamid.IN-'.$letter)->sole();
        expect($message->conversation->contact->name)->toBe('Test '.$letter)
            ->and($message->conversation->unread_count)->toBe(1);
    }
    recoveryPost($payload)->assertOk();
    Queue::assertPushed(ProcessWhatsAppEvent::class, 6);
});

it('does not merge another configured phone account into this inbox', function () {
    $value = recoveryValue();
    $value['metadata']['phone_number_id'] = 'OTHER-PHONE';
    recoveryPost(recoveryPayload($value))->assertOk();
    $event = WebhookEvent::query()->sole();
    processRecoveryEvent($event);
    expect($event->refresh()->status)->toBe(WebhookStatus::Ignored)->and(Message::query()->count())->toBe(0);
});

it('recovers a received row after an intake crash before enqueue', function () {
    $event = WebhookEvent::query()->create([
        'provider' => 'whatsapp', 'external_id' => 'wamid.RECOVERY', 'type' => 'message',
        'payload' => recoveryValue(), 'signature_valid' => true, 'status' => WebhookStatus::Received,
    ]);
    expect(Artisan::call('woodex:retry-whatsapp-webhooks'))->toBe(0);
    Queue::assertPushed(ProcessWhatsAppEvent::class, 1);
    expect(Artisan::call('woodex:retry-whatsapp-webhooks'))->toBe(0);
    Queue::assertPushed(ProcessWhatsAppEvent::class, 1); // live queue lease prevents duplicate enqueue
    processRecoveryEvent($event);
    expect($event->refresh()->status)->toBe(WebhookStatus::Processed)->and(Message::query()->count())->toBe(1);
});

it('recovers an expired queue lease without duplicating committed business work', function () {
    recoveryPost(recoveryPayload(recoveryValue()))->assertOk();
    $event = WebhookEvent::query()->sole();
    $this->travel(6)->minutes();
    expect(Artisan::call('woodex:retry-whatsapp-webhooks'))->toBe(0);
    Queue::assertPushed(ProcessWhatsAppEvent::class, 2);
    processRecoveryEvent($event);
    processRecoveryEvent($event);
    expect(Message::query()->count())->toBe(1)->and($event->refresh()->attempts)->toBe(1);
    expect(Artisan::call('woodex:retry-whatsapp-webhooks'))->toBe(0);
    Queue::assertPushed(ProcessWhatsAppEvent::class, 2);
});

it('retries a failed legacy batched event by its exact id rather than the first message', function () {
    $payload = recoveryValue('wamid.FIRST');
    $second = recoveryValue('wamid.SECOND', '923000000002', 'Test second');
    $payload['messages'][] = $second['messages'][0];
    $payload['contacts'][] = $second['contacts'][0];
    $event = WebhookEvent::query()->create([
        'provider' => 'whatsapp', 'external_id' => 'wamid.SECOND', 'type' => 'message',
        'payload' => $payload, 'signature_valid' => true, 'status' => WebhookStatus::Failed,
    ]);
    recoveryPost(recoveryPayload($second))->assertOk();
    Queue::assertPushed(ProcessWhatsAppEvent::class, 1);
    processRecoveryEvent($event);
    expect($event->refresh()->status)->toBe(WebhookStatus::Processed)
        ->and(Message::query()->sole()->external_id)->toBe('wamid.SECOND')
        ->and(Message::query()->sole()->conversation->contact->name)->toBe('Test second');
});

it('retains early delivery statuses for recovery when the outbound row arrives later', function () {
    $value = ['statuses' => [['id' => 'wamid.NOT-YET-SAVED', 'status' => 'delivered']]];
    recoveryPost(recoveryPayload($value))->assertOk();
    $event = WebhookEvent::query()->sole();
    processRecoveryEvent($event);
    expect($event->refresh()->status)->toBe(WebhookStatus::Failed)->and($event->attempts)->toBe(1);
    $message = Message::factory()->outbound()->create(['external_id' => 'wamid.NOT-YET-SAVED']);
    $this->travel(61)->seconds();
    expect(Artisan::call('woodex:retry-whatsapp-webhooks'))->toBe(0);
    processRecoveryEvent($event);
    expect($event->refresh()->status)->toBe(WebhookStatus::Processed)
        ->and($event->error)->toBeNull()->and($event->next_attempt_at)->toBeNull()
        ->and($message->refresh()->status)->toBe(MessageStatus::Delivered);
});

it('bounds poison event retries and requires explicit reviewed redrive', function () {
    recoveryPost(recoveryPayload(['statuses' => [['id' => 'wamid.MISSING', 'status' => 'delivered']]]))->assertOk();
    $event = WebhookEvent::query()->sole();
    for ($attempt = 1; $attempt <= ProcessWhatsAppEvent::MAX_ATTEMPTS; $attempt++) {
        processRecoveryEvent($event);
        expect($event->refresh()->attempts)->toBe($attempt)->and($event->status)->toBe(WebhookStatus::Failed);
        $this->travel(20)->minutes();
    }
    expect($event->next_attempt_at)->toBeNull()->and($event->error)->toBe('Retry limit reached; staff review required.');
    expect(Artisan::call('woodex:retry-whatsapp-webhooks'))->toBe(0);
    Queue::assertPushed(ProcessWhatsAppEvent::class, 1);
    expect(Artisan::call('woodex:retry-whatsapp-webhooks', ['--event' => $event->id]))->toBe(0);
    expect($event->refresh()->attempts)->toBe(0);
    Queue::assertPushed(ProcessWhatsAppEvent::class, 2);
    expect(Artisan::call('woodex:retry-whatsapp-webhooks', ['--event' => 'invalid']))->toBe(1);
    expect(Artisan::call('woodex:retry-whatsapp-webhooks', ['--limit' => '1001']))->toBe(1);
});

it('does not redrive processed ignored untrusted or non-whatsapp events', function (string $case) {
    $event = WebhookEvent::query()->create([
        'provider' => $case === 'provider' ? 'telegram' : 'whatsapp',
        'external_id' => 'wamid.RECOVERY', 'type' => 'message', 'payload' => recoveryValue(),
        'signature_valid' => $case !== 'signature',
        'status' => match ($case) {
            'processed' => WebhookStatus::Processed,
            'ignored' => WebhookStatus::Ignored,
            default => WebhookStatus::Failed,
        },
    ]);
    expect(Artisan::call('woodex:retry-whatsapp-webhooks', ['--event' => $event->id]))->toBe(1);
    app(QueueWhatsAppEvent::class)->handle($event->id);
    processRecoveryEvent($event);
    expect(Message::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['processed', 'ignored', 'signature', 'provider']);

it('keeps the entire batch durable when enqueue is unavailable and logs no provider content', function () {
    Log::spy();
    $this->mock(Factory::class, fn ($mock) => $mock->shouldReceive('connection')->andThrow(new RuntimeException('PRIVATE-PROVIDER-BODY')));
    recoveryPost(recoveryPayload(recoveryValue()))->assertOk();
    expect(WebhookEvent::query()->sole()->status)->toBe(WebhookStatus::Received);
    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $message === 'WhatsApp event enqueue failed.' && $context === ['event_id' => WebhookEvent::query()->sole()->id, 'exception' => RuntimeException::class]);
});

it('rolls back a domain write when processing fails and stores only a safe error', function () {
    recoveryPost(recoveryPayload(recoveryValue()))->assertOk();
    $event = WebhookEvent::query()->sole();
    Message::creating(function () {
        throw new RuntimeException('PRIVATE-PROVIDER-BODY');
    });
    processRecoveryEvent($event);
    expect($event->refresh()->status)->toBe(WebhookStatus::Failed)
        ->and($event->error)->toBe('Processing failed; retry scheduled.')
        ->and(Message::query()->count())->toBe(0)->and(Conversation::query()->count())->toBe(0)
        ->and(Contact::query()->where('whatsapp', '923000000001')->count())->toBe(0);
});

it('does not reopen the service window or replace the latest preview on delayed recovery', function () {
    $old = recoveryValue('wamid.OLD');
    $old['messages'][0]['timestamp'] = (string) now()->subHours(30)->timestamp;
    recoveryPost(recoveryPayload($old))->assertOk();
    processRecoveryEvent(WebhookEvent::query()->sole());
    $conversation = Conversation::query()->sole();
    expect($conversation->isInWindow())->toBeFalse();

    $new = recoveryValue('wamid.NEW');
    $new['messages'][0]['text']['body'] = 'Most recent enquiry';
    recoveryPost(recoveryPayload($new))->assertOk();
    processRecoveryEvent(WebhookEvent::query()->where('external_id', 'wamid.NEW')->sole());
    $window = $conversation->refresh()->window_expires_at->toDateTimeString();
    $last = $conversation->last_message_at->toDateTimeString();
    $older = recoveryValue('wamid.OLDER');
    $older['messages'][0]['timestamp'] = (string) now()->subHours(35)->timestamp;
    recoveryPost(recoveryPayload($older))->assertOk();
    processRecoveryEvent(WebhookEvent::query()->where('external_id', 'wamid.OLDER')->sole());
    expect($conversation->refresh()->window_expires_at->toDateTimeString())->toBe($window)
        ->and($conversation->last_message_at->toDateTimeString())->toBe($last)
        ->and($conversation->last_preview)->toBe('Most recent enquiry')
        ->and($conversation->unread_count)->toBe(3);
});

it('never downgrades a delivered or read message because a failure arrives late', function () {
    $message = Message::factory()->outbound()->create(['external_id' => 'wamid.ORDERED']);
    $record = app(RecordMessageStatus::class);
    $record->handle('wamid.ORDERED', 'failed', [['code' => 123, 'title' => 'Test failure']]);
    $record->handle('wamid.ORDERED', 'delivered');
    $record->handle('wamid.ORDERED', 'failed');
    expect($message->refresh()->status)->toBe(MessageStatus::Delivered);
    $record->handle('wamid.ORDERED', 'read');
    $record->handle('wamid.ORDERED', 'failed');
    expect($message->refresh()->status)->toBe(MessageStatus::Read);
});

it('does not dispatch a job when the admission transaction rolls back', function () {
    // QueueFake intentionally ignores afterCommit; use the actual database queue for this boundary.
    $manager = new QueueManager(app());
    $manager->addConnector('database', fn () => new DatabaseConnector(app('db')));
    Queue::swap($manager);
    config(['queue.default' => 'database']);
    $event = WebhookEvent::query()->create([
        'provider' => 'whatsapp', 'external_id' => 'wamid.RECOVERY', 'type' => 'message',
        'payload' => recoveryValue(), 'signature_valid' => true, 'status' => WebhookStatus::Received,
    ]);
    expect(fn () => DB::transaction(function () use ($event) {
        app(QueueWhatsAppEvent::class)->handle($event->id);
        throw new RuntimeException('Test rollback');
    }))->toThrow(RuntimeException::class);
    expect(DB::table('jobs')->count())->toBe(0)->and($event->refresh()->queued_at)->toBeNull();
});

it('rolls back business writes together with a failed processed marker and retries once', function () {
    recoveryPost(recoveryPayload(recoveryValue()))->assertOk();
    $event = WebhookEvent::query()->sole();
    WebhookEvent::updating(function (WebhookEvent $row) {
        static $failed = false;
        if (! $failed && $row->status === WebhookStatus::Processed) {
            $failed = true;
            throw new RuntimeException('Test failure after business write');
        }
    });
    processRecoveryEvent($event);
    expect($event->refresh()->status)->toBe(WebhookStatus::Failed)->and(Message::query()->count())->toBe(0);
    $this->travel(61)->seconds();
    processRecoveryEvent($event);
    processRecoveryEvent($event);
    expect($event->refresh()->status)->toBe(WebhookStatus::Processed)->and($event->attempts)->toBe(2)
        ->and(Message::query()->count())->toBe(1)->and(Conversation::query()->sole()->unread_count)->toBe(1);
});

it('does not duplicate an inbound message already written by the legacy receiver before its ledger failed', function () {
    $message = Message::factory()->inbound()->create(['external_id' => 'wamid.RECOVERY']);
    $event = WebhookEvent::query()->create([
        'provider' => 'whatsapp', 'external_id' => 'wamid.RECOVERY', 'type' => 'message',
        'payload' => recoveryValue(), 'signature_valid' => true, 'status' => WebhookStatus::Failed,
    ]);
    processRecoveryEvent($event);
    expect($event->refresh()->status)->toBe(WebhookStatus::Processed)
        ->and(Message::query()->sole()->id)->toBe($message->id);
});

it('runs the admitted event through the actual database queue without business work in the HTTP response', function () {
    $manager = new QueueManager(app());
    $manager->addConnector('database', fn () => new DatabaseConnector(app('db')));
    Queue::swap($manager);
    config(['queue.default' => 'database']);
    recoveryPost(recoveryPayload(recoveryValue()))->assertOk();
    expect(DB::table('jobs')->count())->toBe(1)->and(Message::query()->count())->toBe(0);
    expect(Artisan::call('queue:work', ['--once' => true, '--tries' => 1]))->toBe(0);
    expect(DB::table('jobs')->count())->toBe(0)->and(Message::query()->count())->toBe(1)
        ->and(WebhookEvent::query()->sole()->status)->toBe(WebhookStatus::Processed);
});
