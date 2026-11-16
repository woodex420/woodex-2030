<?php

declare(strict_types=1);

namespace App\Domain\System\Actions;

use App\Domain\System\Models\PendingTelegramMessage;
use App\Domain\System\Models\TelegramLink;
use App\Integrations\Telegram\Contracts\TelegramGateway;

/**
 * S8 / TGM-008: deliver what waited out a quiet period.
 *
 * Runs every minute behind woodex:telegram-flush. A chat only receives its backlog once
 * its mute has actually ended, messages go out oldest first, and anything that could not
 * be sent stays queued for the next pass. Delivered rows are pruned after a week.
 */
final class FlushPendingTelegramMessages
{
    public function __construct(
        private readonly TelegramGateway $gateway,
    ) {}

    /** @return array{links: int, sent: int, pending: int} */
    public function handle(): array
    {
        PendingTelegramMessage::query()
            ->whereNotNull('delivered_at')
            ->where('delivered_at', '<', now()->subDays(7))
            ->delete();

        $links = TelegramLink::query()->usable()
            ->whereHas('pendingMessages', fn ($query) => $query->whereNull('delivered_at'))
            ->with('user')
            ->get()
            ->filter(fn (TelegramLink $link): bool => $link->muted_until === null || ! $link->muted_until->isFuture());

        if ($links->isEmpty() || ! $this->gateway->isConfigured()) {
            return ['links' => 0, 'sent' => 0, 'pending' => $this->pendingCount()];
        }

        $sent = 0;

        foreach ($links as $link) {
            $messages = PendingTelegramMessage::query()
                ->where('telegram_link_id', $link->id)
                ->whereNull('delivered_at')
                ->orderBy('id')
                ->get();

            foreach ($messages as $message) {
                // Re-read every delivery: role, activity, unlink, private-chat and mute may change mid-flush.
                $current = TelegramLink::query()->usable()->find($link->id);
                if ($current === null || ($current->muted_until !== null && $current->muted_until->isFuture())) {
                    break;
                }
                if (! $this->gateway->send((int) $current->chat_id, $message->toTelegramMessage())) {
                    // Leave it queued; the next minute tries again.
                    continue;
                }

                $message->forceFill(['delivered_at' => now()])->save();
                $sent++;
            }
        }

        return ['links' => $links->count(), 'sent' => $sent, 'pending' => $this->pendingCount()];
    }

    private function pendingCount(): int
    {
        return (int) PendingTelegramMessage::query()->whereNull('delivered_at')->count();
    }
}
