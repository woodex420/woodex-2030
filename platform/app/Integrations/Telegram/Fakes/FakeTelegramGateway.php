<?php

declare(strict_types=1);

namespace App\Integrations\Telegram\Fakes;

use App\Integrations\Telegram\Contracts\TelegramGateway;
use App\Integrations\Telegram\TelegramMessage;

/**
 * In-memory Telegram for tests: `$fake = FakeTelegramGateway::bind();` then assert on $fake->sent.
 */
final class FakeTelegramGateway implements TelegramGateway
{
    /** @var list<array{chat_id: int|string, message: TelegramMessage}> */
    public array $sent = [];

    /** @var list<array{url: string, secret: string}> */
    public array $webhooks = [];

    /** @var list<array{id: string, text: string}> */
    public array $answeredCallbacks = [];

    /** @var array<string, mixed> */
    public array $diagnostics = ['api_ok' => true, 'bot_username' => 'WoodexInteriorBot', 'bot_matches' => true, 'webhook_set' => true, 'webhook_matches' => true, 'pending_updates' => 0, 'last_error' => '', 'last_error_at' => null];

    /** @return array<string, mixed> */
    public function connectionStatus(string $expectedUrl, string $expectedUsername): array
    {
        return $this->diagnostics;
    }

    public static function bind(): self
    {
        $fake = new self;
        app()->instance(TelegramGateway::class, $fake);

        return $fake;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(int|string $chatId, TelegramMessage $message): bool
    {
        $this->sent[] = ['chat_id' => $chatId, 'message' => $message];

        return true;
    }

    public function setWebhook(string $url, string $secretToken): bool
    {
        $this->webhooks[] = ['url' => $url, 'secret' => $secretToken];

        return true;
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text): bool
    {
        $this->answeredCallbacks[] = ['id' => $callbackQueryId, 'text' => $text];

        return true;
    }

    /**
     * @return list<string>
     */
    public function textsFor(int|string $chatId): array
    {
        return array_values(array_map(
            static fn (array $item): string => $item['message']->text,
            array_filter($this->sent, static fn (array $item): bool => (string) $item['chat_id'] === (string) $chatId),
        ));
    }
}
