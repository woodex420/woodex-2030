<?php

declare(strict_types=1);

namespace App\Integrations\Telegram\Contracts;

use App\Integrations\Telegram\TelegramMessage;

/**
 * Telegram Bot API boundary (D-23). Real client: TelegramBotApi. Tests: FakeTelegramGateway.
 */
interface TelegramGateway
{
    public function isConfigured(): bool;

    public function send(int|string $chatId, TelegramMessage $message): bool;

    /** @return array<string, mixed> */
    public function connectionStatus(string $expectedUrl, string $expectedUsername): array;

    public function setWebhook(string $url, string $secretToken): bool;

    /**
     * Close the loop on an inline button press (TGM-005): a small toast on the button the user just pressed.
     */
    public function answerCallbackQuery(string $callbackQueryId, string $text): bool;
}
