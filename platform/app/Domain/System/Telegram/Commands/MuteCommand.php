<?php

declare(strict_types=1);

namespace App\Domain\System\Telegram\Commands;

use App\Domain\System\Telegram\TelegramCommand;
use App\Models\User;

/**
 * /mute — quiet this chat for a few hours (TGM-004): alerts stop arriving, the working day does not.
 *
 * Uses the same `muted_until` the alert channel already respects, so one switch governs everything, and a
 * critical alert still waits politely rather than being lost — unmuting (or the hours passing) delivers again.
 */
final class MuteCommand implements TelegramCommand
{
    public function name(): string
    {
        return '/mute';
    }

    public function usage(): string
    {
        return '/mute <hours|off>';
    }

    public function description(): string
    {
        return 'quiet this chat for a few hours (or off)';
    }

    public function permission(): ?string
    {
        return null;
    }

    public function handle(User $user, string $argument): string
    {
        $link = $user->telegramLink;

        if ($link === null) {
            return 'This chat is not linked yet, so there is nothing to mute.';
        }

        $value = strtolower(trim($argument));

        if ($value === 'off' || $value === '0') {
            $link->muted_until = null;
            $link->save();

            return 'Alerts to this chat are on again.';
        }

        if (! ctype_digit($value) || (int) $value < 1 || (int) $value > 72) {
            return 'How many hours? Between 1 and 72, e.g. /mute 8 — or /mute off to unmute.';
        }

        $hours = (int) $value;
        $link->muted_until = now()->addHours($hours);
        $link->save();

        return sprintf('Alerts to this chat are muted for %d hour(s), until %s. /mute off undoes it.', $hours, $link->muted_until->format('D d M H:i'));
    }
}
