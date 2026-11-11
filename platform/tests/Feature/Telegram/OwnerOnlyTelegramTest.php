<?php

declare(strict_types=1);

use App\Domain\Crm\Models\Lead;
use App\Domain\System\Actions\FlushPendingTelegramMessages;
use App\Domain\System\Actions\HandleTelegramCommand;
use App\Domain\System\Actions\ManageStaff;
use App\Domain\System\Models\ChangeRequest;
use App\Domain\System\Models\PendingTelegramMessage;
use App\Domain\System\Models\TelegramLink;
use App\Domain\System\Telegram\TelegramCallbackBus;
use App\Domain\System\Telegram\TelegramCommandBus;
use App\Integrations\Telegram\Contracts\TelegramGateway;
use App\Integrations\Telegram\Fakes\FakeTelegramGateway;
use App\Integrations\Telegram\TelegramChannel;
use App\Integrations\Telegram\TelegramMessage;
use App\Livewire\Admin\Profile\Security;
use App\Livewire\Admin\System\Integrations;
use App\Models\User;
use App\Notifications\Concerns\SendsToDatabaseAndTelegram;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\SendQueuedNotifications;
use Livewire\Livewire;

beforeEach(function () {
    config(['services.telegram.webhook_secret' => 'owner-only-test-secret', 'services.telegram.bot_token' => 'synthetic-token', 'services.telegram.bot_username' => 'SyntheticWoodexBot']);
    $this->telegram = FakeTelegramGateway::bind();
});

function ownerOnlyProbe(): Notification
{
    return new class extends Notification
    {
        use SendsToDatabaseAndTelegram;

        public function toArray(object $notifiable): array
        {
            return ['title' => 'Synthetic test alert', 'body' => 'In-app alerts are preserved.'];
        }

        public function toTelegram(object $notifiable): TelegramMessage
        {
            return new TelegramMessage('Synthetic private alert');
        }
    };
}

function ownerOnlyLink(User $user, int $chatId = 111): TelegramLink
{
    return TelegramLink::query()->create(['user_id' => $user->id, 'chat_id' => $chatId, 'chat_type' => 'private', 'is_active' => true]);
}

function ownerOnlyUpdate(string $text, int $id = 1): array
{
    return ['update_id' => $id, 'message' => ['message_id' => $id + 100, 'chat' => ['id' => 111, 'type' => 'private'], 'from' => ['id' => 111, 'is_bot' => false], 'text' => $text]];
}

it('denies staff pairing and delivery while preserving their in-app notifications', function (string $role) {
    $user = $this->userWithRole($role);
    ownerOnlyLink($user);
    $user->load('roles', 'telegramLink');

    expect($user->canUseTelegram())->toBeFalse()
        ->and($user->telegramChatIdIgnoringMute())->toBeNull()
        ->and($user->routeNotificationForTelegram())->toBeNull();
    expect(fn () => TelegramLink::issueCodeFor($user))->toThrow(AuthorizationException::class);
    $user->notify(ownerOnlyProbe());
    app(TelegramChannel::class)->send($user, ownerOnlyProbe());
    expect($user->fresh()->notifications)->toHaveCount(1)
        ->and($this->telegram->sent)->toBe([]);
})->with(['admin', 'sales_manager', 'sales', 'designer_pm', 'editor', 'accountant', 'support']);

it('allows an active multi-role owner and preserves private legacy links', function () {
    $owner = $this->userWithRole('owner');
    $owner->assignRole('sales');
    $link = ownerOnlyLink($owner);
    $link->update(['chat_type' => null]);
    expect($owner->canUseTelegram())->toBeTrue()->and($owner->routeNotificationForTelegram())->toBe(111);
    $owner->notify(ownerOnlyProbe());
    expect($this->telegram->textsFor(111))->toBe(['Synthetic private alert'])
        ->and($owner->fresh()->notifications)->toHaveCount(1);
});

it('does not trust cached owner roles or cached private links after authority changes', function (string $change) {
    $owner = $this->userWithRole('owner');
    $link = ownerOnlyLink($owner);
    $owner->load('roles', 'telegramLink');
    match ($change) {
        'demoted' => $owner->fresh()->syncRoles(['sales']),
        'disabled' => User::query()->whereKey($owner->id)->update(['is_active' => false]),
        'deleted' => $owner->fresh()->delete(),
        'unlinked' => $link->update(['is_active' => false]),
        'group' => $link->update(['chat_type' => 'supergroup']),
        'negative chat' => $link->update(['chat_id' => -111]),
        'changed mute' => $link->update(['muted_until' => now()->addHour()]),
    };
    expect($owner->routeNotificationForTelegram())->toBeNull();
    app(TelegramChannel::class)->send($owner, ownerOnlyProbe());
    expect($this->telegram->sent)->toBe([]);
})->with(['demoted', 'disabled', 'deleted', 'unlinked', 'group', 'negative chat', 'changed mute']);

it('rechecks owner authority when an already queued notification executes', function () {
    $owner = $this->userWithRole('owner');
    ownerOnlyLink($owner);
    $job = new SendQueuedNotifications($owner, ownerOnlyProbe(), [TelegramChannel::class]);
    $owner->fresh()->syncRoles(['sales']);
    $job->handle(app(ChannelManager::class));
    expect($this->telegram->sent)->toBe([]);
});

it('refuses anonymous Telegram routing as an owner-only bypass', function () {
    $anonymous = (new AnonymousNotifiable)->route('telegram', 111);
    app(TelegramChannel::class)->send($anonymous, ownerOnlyProbe());
    expect($this->telegram->sent)->toBe([]);
});

it('does not queue or flush private content after demotion', function () {
    $owner = $this->userWithRole('owner');
    $link = ownerOnlyLink($owner);
    PendingTelegramMessage::queue($link, new TelegramMessage('Previously queued synthetic alert'));
    $owner->fresh()->syncRoles(['sales']);
    expect(PendingTelegramMessage::queue($link, new TelegramMessage('Refused new alert')))->toBeNull();
    expect(app(FlushPendingTelegramMessages::class)->handle())->toBe(['links' => 0, 'sent' => 0, 'pending' => 1])
        ->and($this->telegram->sent)->toBe([])
        ->and(PendingTelegramMessage::query()->sole()->delivered_at)->toBeNull();
});

it('rechecks every pending delivery if authority changes during a flush', function () {
    $owner = $this->userWithRole('owner');
    $link = ownerOnlyLink($owner);
    PendingTelegramMessage::queue($link, new TelegramMessage('First synthetic alert'));
    PendingTelegramMessage::queue($link, new TelegramMessage('Second synthetic alert'));
    $gateway = Mockery::mock(TelegramGateway::class);
    $gateway->shouldReceive('isConfigured')->once()->andReturnTrue();
    $gateway->shouldReceive('send')->once()->andReturnUsing(function () use ($owner): bool {
        $owner->fresh()->syncRoles(['sales']);

        return true;
    });
    expect((new FlushPendingTelegramMessages($gateway))->handle())->toBe(['links' => 1, 'sent' => 1, 'pending' => 1]);
});

it('invalidates a demoted owners pairing code and active link through staff management', function () {
    $by = $this->userWithRole('owner');
    $owner = $this->userWithRole('owner');
    ownerOnlyLink($owner);
    TelegramLink::issueCodeFor($owner);
    app(ManageStaff::class)->update($owner, $by, $owner->name, 'sales');
    $link = $owner->telegramLink()->sole();
    expect($link->is_active)->toBeFalse()->and($link->link_code)->toBeNull()->and($link->link_code_expires_at)->toBeNull();
});

it('rejects an old owner code after role demotion at both pairing entry points', function () {
    $owner = $this->userWithRole('owner');
    $link = TelegramLink::issueCodeFor($owner);
    $code = $link->link_code;
    $owner->fresh()->syncRoles(['sales']);
    $this->postJson('/webhooks/telegram', ownerOnlyUpdate('/start '.$code), ['X-Telegram-Bot-Api-Secret-Token' => 'owner-only-test-secret'])->assertOk();
    app(HandleTelegramCommand::class)->handle(ownerOnlyUpdate('/start '.$code)['message']);
    expect($link->fresh()->chat_id)->toBeNull()->and($link->fresh()->link_code)->toBe($code);
});

it('denies staff commands at both webhooks and direct buses without creating business records', function () {
    $sales = $this->userWithRole('sales');
    ownerOnlyLink($sales);
    $payload = ownerOnlyUpdate('/idea Unauthorized synthetic request');
    $this->postJson('/webhooks/telegram', $payload, ['X-Telegram-Bot-Api-Secret-Token' => 'owner-only-test-secret'])->assertOk();
    expect(app(HandleTelegramCommand::class)->handle($payload['message'])['text'])->toContain('only to the active owner')
        ->and(app(TelegramCommandBus::class)->dispatch($sales, '/leads', ''))->toContain('only to the active owner')
        ->and(app(TelegramCallbackBus::class)->dispatch($sales, TelegramCallbackBus::button('assign', 1)))->toContain('only to the active owner')
        ->and(ChangeRequest::query()->count())->toBe(0);
});

it('ignores forged callback authority without answering or changing the lead', function (string $case) {
    $owner = $this->userWithRole('owner');
    $link = ownerOnlyLink($owner);
    $lead = Lead::factory()->create(['owner_id' => null]);
    $callback = ['id' => 'synthetic-callback', 'from' => ['id' => 111, 'is_bot' => false], 'data' => TelegramCallbackBus::button('assign', $lead->id), 'message' => ['chat' => ['id' => 111, 'type' => 'private']]];
    match ($case) {
        'wrong sender' => $callback['from']['id'] = 222,
        'missing sender' => $callback['from'] = [],
        'bot sender' => $callback['from']['is_bot'] = true,
        'group chat' => $callback['message']['chat']['type'] = 'group',
        'unlinked' => $link->update(['is_active' => false]),
        'demoted' => $owner->fresh()->syncRoles(['sales']),
    };
    $this->postJson('/webhooks/telegram', ['update_id' => 1, 'callback_query' => $callback], ['X-Telegram-Bot-Api-Secret-Token' => 'owner-only-test-secret'])->assertOk();
    expect($lead->fresh()->owner_id)->toBeNull()->and($this->telegram->sent)->toBe([])->and($this->telegram->answeredCallbacks)->toBe([]);
})->with(['wrong sender', 'missing sender', 'bot sender', 'group chat', 'unlinked', 'demoted']);

it('keeps staff security settings but hides Telegram pairing and diagnostic details', function () {
    $admin = $this->userWithRole('admin');
    ownerOnlyLink($admin);
    Livewire::actingAs($admin)->test(Security::class)
        ->assertSee('Two-factor authentication')->assertSee('Telegram is available to the owner only')
        ->assertDontSee('Telegram connection health')->assertViewHas('telegram', null)
        ->call('createTelegramCode')->assertForbidden();
});

it('denies owner-only integration operations even to an admin with integration permission', function (string $method) {
    Livewire::actingAs($this->userWithRole('admin'))->test(Integrations::class)
        ->assertViewHas('providers', fn ($providers): bool => ! collect($providers)->contains('key', 'telegram'))
        ->call($method)->assertForbidden();
    expect($this->telegram->sent)->toBe([])->and($this->telegram->webhooks)->toBe([]);
})->with(['checkTelegramConfiguration', 'reloadTelegramConfiguration', 'checkTelegramConnection', 'testTelegram', 'setTelegramWebhook']);
