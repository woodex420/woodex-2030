<?php

declare(strict_types=1);

namespace App\Integrations\Telegram;

use App\Domain\System\Models\PendingTelegramMessage;
use App\Integrations\Telegram\Contracts\TelegramGateway;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Laravel notification channel. Notifications implement toTelegram(object $notifiable): TelegramMessage.
 * Delivery failures are logged, never thrown, so an outage cannot break saving business data.
 *
 * Quiet hours (S8 / TGM-008): a muted chat is never dropped — the message waits in
 * PendingTelegramMessage until woodex:telegram-flush delivers it after the mute. A
 * notification may declare isCritical(): true to bypass the mute entirely.
 */
final class TelegramChannel
{
    public function __construct(private readonly TelegramGateway $gateway) {}

    public function send(object $notifiable, Notification $notification): void
    {
        // Anonymous routes and legacy staff links cannot bypass owner-only delivery.
        if (! $notifiable instanceof User) {
            return;
        }

        $notifiable = User::query()->with('telegramLink')->find($notifiable->id);
        if ($notifiable === null || ! $notifiable->canUseTelegram()) {
            return;
        }

        if (! method_exists($notification, 'toTelegram')) {
            return;
        }

        $chatId = $notifiable->telegramChatIdIgnoringMute();

        if ($chatId === null || ! $this->gateway->isConfigured()) {
            return;
        }

        if ($this->isMuted($notifiable) && ! $this->isCritical($notification)) {
            $link = $notifiable->telegramLink;

            if ($link !== null) {
                PendingTelegramMessage::queue($link, $notification->toTelegram($notifiable));
            }

            return;
        }

        try {
            $this->gateway->send($chatId, $notification->toTelegram($notifiable));
        } catch (Throwable $exception) {
            Log::warning('Telegram notification delivery failed.', ['exception' => $exception::class]);
        }
    }

    private function isMuted(User $user): bool
    {
        $link = $user->telegramLink;

        return $link !== null && $link->muted_until !== null && $link->muted_until->isFuture();
    }

    /** Critical alerts (a notification opting in with isCritical()) ignore quiet hours. */
    private function isCritical(Notification $notification): bool
    {
        return method_exists($notification, 'isCritical') && (bool) $notification->isCritical();
    }
}
