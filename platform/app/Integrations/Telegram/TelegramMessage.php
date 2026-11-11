<?php

declare(strict_types=1);

namespace App\Integrations\Telegram;

/**
 * Outgoing Telegram message (HTML parse mode) with optional inline URL buttons.
 */
final readonly class TelegramMessage
{
    /**
     * @param  list<list<array{text: string, url?: string, callback_data?: string}>>  $buttons  rows of buttons
     */
    public function __construct(
        public string $text,
        public array $buttons = [],
    ) {}

    public static function escape(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
