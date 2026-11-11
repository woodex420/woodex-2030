<?php

use App\Domain\Sales\Models\Payment;
use App\Domain\System\Enums\WebhookStatus;
use App\Domain\System\Models\ChangeRequest;
use App\Domain\System\Models\TelegramLink;
use App\Domain\System\Models\WebhookEvent;
use App\Integrations\Telegram\Contracts\TelegramGateway;
use App\Integrations\Telegram\Fakes\FakeTelegramGateway;
use App\Integrations\Telegram\TelegramChannel;
use App\Integrations\Telegram\TelegramMessage;
use App\Livewire\Admin\System\Integrations;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    config(['services.telegram.webhook_secret' => 'security-test-secret', 'services.telegram.bot_username' => 'TestWoodexBot', 'services.telegram.allow_legacy_webhook' => false]);
    $this->telegram = FakeTelegramGateway::bind();
});

function secureTelegramUpdate(int $id = 1, string $text = '/help', int $sender = 101): array
{
    return ['update_id' => $id, 'message' => [
        'message_id' => $id + 100, 'chat' => ['id' => $sender, 'type' => 'private'],
        'from' => ['id' => $sender, 'is_bot' => false, 'first_name' => 'Test staff'], 'text' => $text,
    ]];
}

function secureTelegramPost(array $update, string $url = '/webhooks/telegram', ?string $header = 'security-test-secret')
{
    return test()->postJson($url, $update, $header === null ? [] : ['X-Telegram-Bot-Api-Secret-Token' => $header]);
}

it('requires the official secret header on the stable endpoint', function (?string $header) {
    secureTelegramPost(secureTelegramUpdate(), header: $header)->assertForbidden();
    expect(WebhookEvent::query()->count())->toBe(0)->and($this->telegram->sent)->toBe([]);
})->with([null, 'wrong-secret']);

it('allows the stable endpoint only with a configured matching header', function () {
    secureTelegramPost(secureTelegramUpdate())->assertOk();
    expect(WebhookEvent::query()->sole()->status)->toBe(WebhookStatus::Processed);
    config(['services.telegram.webhook_secret' => '']);
    secureTelegramPost(secureTelegramUpdate(2))->assertNotFound();
});

it('requires a header even on the legacy path by default', function () {
    secureTelegramPost(secureTelegramUpdate(), '/webhooks/telegram/security-test-secret', null)->assertForbidden();
    secureTelegramPost(secureTelegramUpdate(), '/webhooks/telegram/security-test-secret')->assertOk();
    secureTelegramPost(secureTelegramUpdate(2), '/webhooks/telegram/wrong')->assertNotFound();
});

it('restricts explicit temporary legacy mode to the correct path and never accepts a wrong header', function () {
    config(['services.telegram.allow_legacy_webhook' => true]);
    secureTelegramPost(secureTelegramUpdate(), '/webhooks/telegram/security-test-secret', null)->assertOk();
    secureTelegramPost(secureTelegramUpdate(2), '/webhooks/telegram', null)->assertForbidden();
    secureTelegramPost(secureTelegramUpdate(3), '/webhooks/telegram/security-test-secret', 'wrong')->assertForbidden();
});

it('registers and diagnoses the stable URL without putting secrets in its query or path', function () {
    config(['services.telegram.bot_token' => 'fake-token']);
    URL::forceScheme('https');
    $this->actingAs($this->userWithRole('owner'));
    Livewire::test(Integrations::class)->call('setTelegramWebhook')->assertDispatched('toast', type: 'success');
    expect($this->telegram->webhooks)->toHaveCount(1)
        ->and($this->telegram->webhooks[0]['url'])->toEndWith('/webhooks/telegram')
        ->not->toContain('security-test-secret')->not->toContain('?')
        ->and($this->telegram->webhooks[0]['secret'])->toBe('security-test-secret');
});

it('rejects group channel forged bot forwarded and inline identities without consuming a link code', function (string $case) {
    $user = $this->userWithRole('owner');
    $link = TelegramLink::issueCodeFor($user);
    $update = secureTelegramUpdate(text: '/start '.$link->link_code);
    match ($case) {
        'group' => $update['message']['chat'] = ['id' => -500, 'type' => 'group'],
        'supergroup' => $update['message']['chat'] = ['id' => -500, 'type' => 'supergroup'],
        'channel' => $update['message']['chat']['type'] = 'channel',
        'forged' => $update['message']['from']['id'] = 202,
        'bot' => $update['message']['from']['is_bot'] = true,
        'sender_chat' => $update['message']['sender_chat'] = ['id' => -500],
        'forwarded' => $update['message']['forward_origin'] = ['type' => 'user'],
        'old_forwarded' => $update['message']['forward_from'] = ['id' => 202],
        'via_bot' => $update['message']['via_bot'] = ['id' => 303],
        'missing_sender' => $update['message']['from'] = [],
        'bool_sender' => $update['message']['from']['id'] = true,
        'wrong_bot' => $update['message']['text'] = '/start@OtherBot '.$link->link_code,
    };
    secureTelegramPost($update)->assertOk();
    expect($link->refresh()->chat_id)->toBeNull()->and($link->link_code)->not->toBeNull()
        ->and(ChangeRequest::query()->count())->toBe(0)->and($this->telegram->sent)->toBe([])
        ->and(WebhookEvent::query()->sole()->status)->toBe(WebhookStatus::Ignored);
})->with(['group', 'supergroup', 'channel', 'forged', 'bot', 'sender_chat', 'forwarded', 'old_forwarded', 'via_bot', 'missing_sender', 'bool_sender', 'wrong_bot']);

it('never grants group members the authority of a previously linked staff user', function () {
    $owner = $this->userWithRole('owner');
    TelegramLink::query()->create(['user_id' => $owner->id, 'chat_id' => -500, 'chat_type' => 'supergroup', 'is_active' => true]);
    $update = secureTelegramUpdate(text: '/idea Pretend to be the owner', sender: 202);
    $update['message']['chat'] = ['id' => -500, 'type' => 'supergroup'];
    secureTelegramPost($update)->assertOk();
    expect(ChangeRequest::query()->count())->toBe(0)->and($owner->fresh()->routeNotificationForTelegram())->toBeNull();
});

it('rejects inactive unlinked group and deleted staff authority', function (string $case) {
    $user = $this->userWithRole('owner');
    $link = TelegramLink::query()->create(['user_id' => $user->id, 'chat_id' => 101, 'is_active' => true, 'chat_type' => 'private']);
    match ($case) {
        'inactive' => $user->update(['is_active' => false]),
        'unlinked' => $link->update(['is_active' => false]),
        'group_link' => $link->update(['chat_type' => 'group']),
        'deleted' => $user->delete(),
    };
    secureTelegramPost(secureTelegramUpdate(text: '/idea Try an unauthorized write'))->assertOk();
    expect(ChangeRequest::query()->count())->toBe(0);
})->with(['inactive', 'unlinked', 'group_link', 'deleted']);

it('refuses inactive expired rotated and used codes', function (string $case) {
    $user = $this->userWithRole('owner');
    $link = TelegramLink::issueCodeFor($user);
    $code = $link->link_code;
    match ($case) {
        'inactive' => $user->update(['is_active' => false]),
        'expired' => $link->update(['link_code_expires_at' => now()->subMinute()]),
        'rotated' => TelegramLink::issueCodeFor($user),
        'used' => $link->update(['link_code' => null, 'link_code_expires_at' => null]),
    };
    secureTelegramPost(secureTelegramUpdate(text: '/start '.$code))->assertOk();
    expect($link->refresh()->chat_id)->toBeNull();
})->with(['inactive', 'expired', 'rotated', 'used']);

it('consumes one code once and never steals a chat already linked to another account', function () {
    $first = TelegramLink::issueCodeFor($this->userWithRole('owner'));
    $second = TelegramLink::issueCodeFor($this->userWithRole('owner'));
    $code = $first->link_code;
    secureTelegramPost(secureTelegramUpdate(text: '/start '.$code))->assertOk();
    secureTelegramPost(secureTelegramUpdate(2, '/start '.$code, 202))->assertOk();
    secureTelegramPost(secureTelegramUpdate(3, '/start '.$second->link_code))->assertOk();
    expect($first->refresh()->chat_id)->toBe(101)->and($first->link_code)->toBeNull()
        ->and($second->refresh()->chat_id)->toBeNull()->and($second->link_code)->not->toBeNull();
});

it('executes business commands only through the permission-gated command centre', function () {
    // Owner decision (17 Oct 2026): the bot keeps the full command centre — /approve and its siblings run
    // behind the linked user's own permissions. What must never happen: an unknown "financial-sounding"
    // command doing anything, an unlinked chat getting authority, or a staff member without the permission.
    $owner = $this->userWithRole('owner');
    TelegramLink::query()->create(['user_id' => $owner->id, 'chat_id' => 101, 'is_active' => true]);

    $sales = $this->userWithRole('sales');
    TelegramLink::query()->create(['user_id' => $sales->id, 'chat_id' => 102, 'is_active' => true]);

    foreach (['/verify 1', '/refund 1', '/void 1'] as $index => $text) {
        secureTelegramPost(secureTelegramUpdate($index + 1, $text))->assertOk();
    }

    // A linked owner asking for an approval that does not exist: the command runs, nothing changes.
    secureTelegramPost(secureTelegramUpdate(4, '/approve 999'))->assertOk();

    // A staff member without the approval permission: refused, nothing changes.
    secureTelegramPost(secureTelegramUpdate(5, '/approve 999', 102))->assertOk();

    // An unlinked chat: no authority at all.
    secureTelegramPost(secureTelegramUpdate(6, '/approve 999', 103))->assertOk();

    expect(ChangeRequest::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and(implode(' ', $this->telegram->textsFor(101)))->toContain('Unknown command')
        ->and(implode(' ', $this->telegram->textsFor(102)))->not->toBeEmpty()
        ->and(implode(' ', $this->telegram->textsFor(103)))->toContain('not linked');
});

it('commits a change request with its replay marker and recovers a failed marker without duplicating the request', function () {
    $owner = $this->userWithRole('owner');
    TelegramLink::query()->create(['user_id' => $owner->id, 'chat_id' => 101, 'is_active' => true]);
    WebhookEvent::updating(function (WebhookEvent $row) {
        static $failed = false;
        if (! $failed && $row->status === WebhookStatus::Processed) {
            $failed = true;
            throw new RuntimeException('PRIVATE-ERROR-CONTENT');
        }
    });
    $payload = secureTelegramUpdate(text: '/issue Original trusted report');
    secureTelegramPost($payload)->assertStatus(503);
    expect(ChangeRequest::query()->count())->toBe(0)->and($this->telegram->sent)->toBe([]);
    $altered = secureTelegramUpdate(text: '/issue Changed report on same update ID');
    secureTelegramPost($altered)->assertOk();
    secureTelegramPost($payload)->assertOk()->assertJsonPath('duplicate', true);
    expect(ChangeRequest::query()->sole()->body)->toBe('Original trusted report')
        ->and($this->telegram->sent)->toHaveCount(1);
});

it('keeps committed command effects when a reply transport fails and logs no raw exception', function () {
    $owner = $this->userWithRole('owner');
    TelegramLink::query()->create(['user_id' => $owner->id, 'chat_id' => 101, 'is_active' => true]);
    Log::spy();
    $this->mock(TelegramGateway::class, fn ($mock) => $mock->shouldReceive('send')->once()->andThrow(new RuntimeException('PRIVATE-TOKEN')));
    $payload = secureTelegramUpdate(text: '/issue A valid report');
    secureTelegramPost($payload)->assertOk();
    secureTelegramPost($payload)->assertOk()->assertJsonPath('duplicate', true);
    expect(ChangeRequest::query()->count())->toBe(1);
    Log::shouldHaveReceived('warning')->with('Telegram command reply failed.', ['event_id' => WebhookEvent::query()->sole()->id, 'exception' => RuntimeException::class]);
});

it('limits link guesses by authenticated sender without blocking a different sender', function () {
    for ($id = 1; $id <= 5; $id++) {
        secureTelegramPost(secureTelegramUpdate($id, '/start WRONGCODE'))->assertOk();
    }
    secureTelegramPost(secureTelegramUpdate(6, '/link WRONGCODE'))->assertStatus(429);
    secureTelegramPost(secureTelegramUpdate(7, '/link WRONGCODE', 202))->assertOk();
    expect(WebhookEvent::query()->count())->toBe(6);
});

it('limits ordinary commands independently from link attempts', function () {
    for ($id = 1; $id <= 30; $id++) {
        secureTelegramPost(secureTelegramUpdate($id))->assertOk();
    }
    secureTelegramPost(secureTelegramUpdate(31))->assertStatus(429);
    secureTelegramPost(secureTelegramUpdate(32, sender: 202))->assertOk();
});

it('ignores malformed update identifiers before admission', function (mixed $id) {
    $payload = secureTelegramUpdate();
    $payload['update_id'] = $id;
    secureTelegramPost($payload)->assertOk();
    expect(WebhookEvent::query()->count())->toBe(0)->and($this->telegram->sent)->toBe([]);
})->with([null, [[]], true, -1, 'abc', 1.25]);

it('does not treat callback or edited-message updates as approval authority', function (string $field) {
    $owner = $this->userWithRole('owner');
    TelegramLink::query()->create(['user_id' => $owner->id, 'chat_id' => 101, 'is_active' => true]);
    $payload = ['update_id' => 1, $field => ['from' => ['id' => 101], 'data' => 'approve:1', 'text' => '/idea Unexpected write', 'chat' => ['id' => 101, 'type' => 'private']]];
    secureTelegramPost($payload)->assertOk();
    expect(ChangeRequest::query()->count())->toBe(0)->and($this->telegram->sent)->toBe([])
        ->and(WebhookEvent::query()->sole()->status)->toBe(WebhookStatus::Ignored);
})->with(['callback_query', 'edited_message', 'channel_post']);

it('rereads staff and notification routing rather than trusting a cached link', function (string $case) {
    $user = $this->userWithRole('owner');
    $link = TelegramLink::query()->create(['user_id' => $user->id, 'chat_id' => 101, 'chat_type' => 'private', 'is_active' => true]);
    $user->load('telegramLink');
    match ($case) {
        'disabled' => User::query()->whereKey($user->id)->update(['is_active' => false]),
        'unlinked' => TelegramLink::query()->whereKey($link->id)->update(['is_active' => false, 'chat_id' => null]),
        'group' => TelegramLink::query()->whereKey($link->id)->update(['chat_type' => 'group']),
        'muted' => TelegramLink::query()->whereKey($link->id)->update(['muted_until' => now()->addHour()]),
    };
    $notification = new class extends Notification
    {
        public function toTelegram(object $notifiable): TelegramMessage
        {
            return new TelegramMessage('Private test alert');
        }
    };
    app(TelegramChannel::class)->send($user, $notification);
    expect($this->telegram->sent)->toBe([]);
})->with(['disabled', 'unlinked', 'group', 'muted']);

it('does not let unauthenticated requests exhaust a real sender rate bucket', function () {
    for ($id = 1; $id <= 35; $id++) {
        secureTelegramPost(secureTelegramUpdate($id), header: 'wrong-secret')->assertForbidden();
    }
    secureTelegramPost(secureTelegramUpdate(36))->assertOk();
    expect(WebhookEvent::query()->count())->toBe(1);
});

it('uses the stable secret-free URL from the console registration path too', function () {
    URL::forceScheme('https');
    expect(Artisan::call('woodex:telegram:set-webhook'))->toBe(0);
    expect($this->telegram->webhooks[0]['url'])->toEndWith('/webhooks/telegram')
        ->not->toContain('security-test-secret')->not->toContain('?');
});
