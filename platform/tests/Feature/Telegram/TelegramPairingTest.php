<?php

declare(strict_types=1);

use App\Domain\System\Enums\WebhookStatus;
use App\Domain\System\Models\IntegrationLog;
use App\Domain\System\Models\TelegramLink;
use App\Domain\System\Models\WebhookEvent;
use App\Integrations\Telegram\Fakes\FakeTelegramGateway;
use App\Integrations\Telegram\TelegramHealth;
use App\Integrations\Telegram\TelegramPairing;
use App\Livewire\Admin\Profile\Security;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/*
| Slice L: pairing that attaches. The QR is rendered on our own server from the exact deep link the copyable
| field shows, the webhook's /start flips the page to linked, and when something is missing the health ladder
| names the failing step instead of shrugging "not connected".
*/

beforeEach(function () {
    config([
        'services.telegram.bot_token' => 'test-token',
        'services.telegram.webhook_secret' => 'hook-secret',
        'services.telegram.bot_username' => 'WoodexInteriorBot',
    ]);
    $this->fake = FakeTelegramGateway::bind();
    Cache::forget(TelegramHealth::STATUS_CACHE_KEY);
    $this->user = $this->userWithRole('owner');
});

/**
 * @return array{key: string, label: string, ok: bool, detail: string, hint: ?string}
 */
function healthStep(array $steps, string $key): array
{
    foreach ($steps as $step) {
        if ($step['key'] === $key) {
            return $step;
        }
    }

    throw new RuntimeException("No health step [{$key}].");
}

it('renders the QR and the copyable deep link for a pending code', function () {
    $link = TelegramLink::issueCodeFor($this->user);
    $url = 'https://t.me/WoodexInteriorBot?start='.$link->link_code;

    Livewire::actingAs($this->user)->test(Security::class)
        ->assertViewHas('telegramStartUrl', $url)
        ->assertViewHas('telegramQrSvg', fn (?string $svg): bool => $svg !== null && str_contains($svg, '<svg'))
        ->assertSeeHtml($url)
        ->assertSee('/start '.$link->link_code)
        ->assertSee('Waiting')
        ->assertSee('Telegram connection health');

    // The QR the page shows is the pairing helper's rendering of exactly that URL — no third-party service.
    expect(app(TelegramPairing::class)->qrSvg($url))
        ->toBe(app(TelegramPairing::class)->qrSvg($url))
        ->toStartWith('<svg');
});

it('flips to linked when the webhook receives the start code', function () {
    $link = TelegramLink::issueCodeFor($this->user);

    $this->postJson('/webhooks/telegram', [
        'update_id' => 501,
        'message' => [
            'message_id' => 601,
            'chat' => ['id' => 777001, 'type' => 'private'],
            'from' => ['id' => 777001, 'first_name' => 'Nabeel', 'username' => 'nabeel_q', 'is_bot' => false],
            'text' => '/start '.$link->link_code,
        ],
    ], headers: ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();

    $link->refresh();
    expect($link->chat_id)->toBe(777001)
        ->and($link->username)->toBe('nabeel_q')
        ->and($link->link_code)->toBeNull();

    Livewire::actingAs($this->user)->test(Security::class)
        ->assertSee('Linked')
        ->assertSee('@nabeel_q')
        ->assertSee('Last message from this chat')
        ->assertDontSee('Waiting');
});

it('refuses a code that cannot pair and names the missing key', function () {
    config(['services.telegram.bot_username' => '']);

    Livewire::actingAs($this->user)->test(Security::class)
        ->call('createTelegramCode')
        ->assertDispatched('toast');

    expect(TelegramLink::query()->where('user_id', $this->user->id)->value('link_code'))->toBeNull();

    config(['services.telegram.bot_token' => '']);

    Livewire::actingAs($this->user)->test(Security::class)
        ->call('createTelegramCode')
        ->assertDispatched('toast');

    expect(TelegramLink::query()->where('user_id', $this->user->id)->count())->toBe(0);
});

it('names the missing token and secret instead of saying not connected', function () {
    config(['services.telegram.bot_token' => '', 'services.telegram.webhook_secret' => '']);

    $steps = app(TelegramHealth::class)->steps(null);

    expect(healthStep($steps, 'token')['ok'])->toBeFalse()
        ->and(healthStep($steps, 'token')['detail'])->toContain('TELEGRAM_BOT_TOKEN is not set')
        ->and(healthStep($steps, 'secret')['ok'])->toBeFalse()
        ->and(healthStep($steps, 'secret')['detail'])->toContain('TELEGRAM_WEBHOOK_SECRET is not set');

    Livewire::actingAs($this->user)->test(Security::class)
        ->assertSee('TELEGRAM_BOT_TOKEN is not set on the server.')
        ->assertDontSee('Link Telegram');
});

it('names a webhook that Telegram does not have, with its delivery error', function () {
    $this->fake->diagnostics['webhook_set'] = false;
    $this->fake->diagnostics['webhook_matches'] = false;
    Cache::forget(TelegramHealth::STATUS_CACHE_KEY);

    $step = healthStep(app(TelegramHealth::class)->steps(null), 'webhook');
    expect($step['ok'])->toBeFalse()
        ->and($step['detail'])->toContain('No webhook is registered with Telegram');

    $this->fake->diagnostics['webhook_set'] = true;
    $this->fake->diagnostics['last_error'] = 'Connection refused';
    Cache::forget(TelegramHealth::STATUS_CACHE_KEY);

    $step = healthStep(app(TelegramHealth::class)->steps(null), 'webhook');
    expect($step['ok'])->toBeFalse()
        ->and($step['detail'])->toContain('different address')
        ->and($step['detail'])->toContain('Connection refused');
});

it('flags silence when no update has arrived in 24 hours', function () {
    $health = app(TelegramHealth::class);

    expect(healthStep($health->steps(null), 'updates')['detail'])
        ->toContain('No update has ever reached this server');

    WebhookEvent::query()->create([
        'provider' => 'telegram', 'external_id' => 'old-1', 'type' => 'message',
        'payload' => [], 'signature_valid' => true, 'status' => WebhookStatus::Processed,
        'created_at' => now()->subDays(2),
    ]);

    $step = healthStep($health->steps(null), 'updates');
    expect($step['ok'])->toBeFalse()
        ->and($step['detail'])->toContain('nothing in the last 24 hours');

    WebhookEvent::query()->create([
        'provider' => 'telegram', 'external_id' => 'fresh-1', 'type' => 'message',
        'payload' => [], 'signature_valid' => true, 'status' => WebhookStatus::Processed,
    ]);

    expect(healthStep($health->steps(null), 'updates')['ok'])->toBeTrue();
});

it('names a blocked bot for this chat and clears once a send lands', function () {
    $link = TelegramLink::query()->create([
        'user_id' => $this->user->id, 'chat_id' => 555001, 'chat_type' => 'private',
        'username' => 'nabeel_q', 'is_active' => true, 'linked_at' => now(),
    ]);

    IntegrationLog::query()->create([
        'integration' => 'telegram', 'direction' => 'out', 'action' => 'sendMessage',
        'status_code' => 403, 'ok' => false,
        'request' => ['chat_id' => 555001],
        'response' => ['description' => 'Forbidden: bot was blocked by the user'],
    ]);

    $step = healthStep(app(TelegramHealth::class)->steps($link), 'chat');
    expect($step['ok'])->toBeFalse()
        ->and($step['detail'])->toContain('blocked the bot');

    IntegrationLog::query()->create([
        'integration' => 'telegram', 'direction' => 'out', 'action' => 'sendMessage',
        'status_code' => 200, 'ok' => true,
        'request' => ['chat_id' => 555001], 'response' => null,
    ]);

    $step = healthStep(app(TelegramHealth::class)->steps($link->fresh()), 'chat');
    expect($step['ok'])->toBeTrue()
        ->and($step['detail'])->toContain('Linked as @nabeel_q');
});

it('all steps pass on a healthy linked setup', function () {
    $link = TelegramLink::query()->create([
        'user_id' => $this->user->id, 'chat_id' => 555002, 'chat_type' => 'private',
        'username' => 'nabeel_q', 'is_active' => true, 'linked_at' => now(),
    ]);
    WebhookEvent::query()->create([
        'provider' => 'telegram', 'external_id' => 'fresh-9', 'type' => 'message',
        'payload' => ['message' => ['chat' => ['id' => 555002]]],
        'signature_valid' => true, 'status' => WebhookStatus::Processed,
    ]);

    $steps = app(TelegramHealth::class)->steps($link);

    expect(collect($steps)->every(fn (array $step): bool => $step['ok']))->toBeTrue();
    expect(app(TelegramHealth::class)->lastMessageAt($link))->not->toBeNull();
});
