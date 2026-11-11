<?php

use App\Domain\System\Models\ChangeRequest;
use App\Domain\System\Models\TelegramLink;
use App\Integrations\Telegram\Fakes\FakeTelegramGateway;

beforeEach(function () {
    config(['services.telegram.webhook_secret' => 'hook-secret']);
    $this->telegram = FakeTelegramGateway::bind();
    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'hook-secret');
});

function telegramUpdate(int $updateId, int $chatId, string $text): array
{
    return [
        'update_id' => $updateId,
        'message' => [
            'message_id' => $updateId + 100,
            'chat' => ['id' => $chatId, 'type' => 'private'],
            'from' => ['id' => $chatId, 'first_name' => 'Nabeel', 'username' => 'nabeel_w'],
            'text' => $text,
        ],
    ];
}

it('rejects requests without the secret', function () {
    $this->postJson('/webhooks/telegram/wrong', telegramUpdate(1, 42, '/help'), headers: ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertNotFound();
});

it('links an owner account with a one-time code', function () {
    $user = $this->userWithRole('owner');
    $link = TelegramLink::issueCodeFor($user);

    $this->postJson('/webhooks/telegram/hook-secret', telegramUpdate(10, 777, '/start '.$link->link_code), headers: ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();

    $link->refresh();
    expect($link->chat_id)->toBe(777)
        ->and($link->link_code)->toBeNull()
        ->and($user->fresh()->routeNotificationForTelegram())->toBe(777)
        ->and($this->telegram->textsFor(777)[0])->toContain('Linked to Woodex OS');
});

it('processes each update only once', function () {
    $this->postJson('/webhooks/telegram/hook-secret', telegramUpdate(20, 42, '/help'), headers: ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();
    $this->postJson('/webhooks/telegram/hook-secret', telegramUpdate(20, 42, '/help'), headers: ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk()->assertJsonPath('duplicate', true);

    expect($this->telegram->sent)->toHaveCount(1);
});

it('turns /idea from a linked user into a change request', function () {
    $owner = $this->userWithRole('owner');
    TelegramLink::query()->create(['user_id' => $owner->id, 'chat_id' => 900, 'is_active' => true, 'linked_at' => now()]);

    $this->postJson('/webhooks/telegram/hook-secret', telegramUpdate(30, 900, '/idea Add a kitchen filter to the portfolio'), headers: ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();

    $request = ChangeRequest::query()->sole();
    expect($request->title)->toBe('Add a kitchen filter to the portfolio')
        ->and($request->requested_by)->toBe($owner->id)
        ->and($request->source)->toBe('telegram')
        ->and($this->telegram->textsFor(900)[0])->toContain($request->reference());
});

it('replies to plain start without requiring a link code', function () {
    $this->postJson('/webhooks/telegram/hook-secret', telegramUpdate(40, 888, '/start'), headers: ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();
    expect($this->telegram->textsFor(888)[0])->toContain('Welcome to Woodex OS');
});

it('escapes owner command help and names for Telegram HTML parse mode', function () {
    $owner = $this->userWithRole('owner');
    TelegramLink::query()->create(['user_id' => $owner->id, 'chat_id' => 888, 'is_active' => true]);
    $this->postJson('/webhooks/telegram/hook-secret', telegramUpdate(41, 888, '/help'), headers: ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();
    expect($this->telegram->textsFor(888)[0])->toContain('&lt;text&gt;')->not->toContain('<text>');

    $user = $this->userWithRole('owner', ['name' => 'Ali & Sons <Owner>']);
    $link = TelegramLink::issueCodeFor($user);
    $this->postJson('/webhooks/telegram/hook-secret', telegramUpdate(42, 889, '/start '.$link->link_code), headers: ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();
    expect($this->telegram->textsFor(889)[0])->toContain('Ali &amp; Sons &lt;Owner&gt;');
});
