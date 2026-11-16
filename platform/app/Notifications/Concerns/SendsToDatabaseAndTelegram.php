<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Integrations\Telegram\TelegramChannel;

/**
 * In-app (bell) always; Telegram only for an active owner with a usable private link.
 */
trait SendsToDatabaseAndTelegram
{
    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (method_exists($notifiable, 'telegramChatIdIgnoringMute') && $notifiable->telegramChatIdIgnoringMute() !== null) {
            $channels[] = TelegramChannel::class;
        }

        return $channels;
    }

    /**
     * Inline "Open" button only for public https URLs (Telegram rejects http/localhost buttons).
     *
     * @return list<list<array{text: string, url: string}>>
     */
    protected function openButton(string $label, string $url): array
    {
        return str_starts_with($url, 'https://') ? [[['text' => $label, 'url' => $url]]] : [];
    }
}
