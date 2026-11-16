<?php

declare(strict_types=1);

namespace App\Domain\System\Alerts;

use App\Integrations\Telegram\TelegramChannel;
use App\Integrations\Telegram\TelegramMessage;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One alert wearing the routing an AlertRule chose (AUT-003): the rule picks who hears it, through
 * which channels, and whether it respects quiet hours. Everything the recipient sees — the in-app
 * row, the email, the Telegram message — comes from the wrapped notification, unchanged; only the
 * envelope differs.
 */
final class RoutedAlert extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  Notification  $inner  the alert being routed, untouched
     * @param  list<string>  $channels  telegram | database | mail
     */
    public function __construct(
        private readonly Notification $inner,
        private readonly array $channels,
        private readonly bool $critical,
    ) {}

    /**
     * The rule's channels, filtered to what this person can actually receive: Telegram needs a
     * linked chat, mail needs an address. The database row is always possible.
     *
     * @param  object  $notifiable
     * @return list<string>
     */
    public function via($notifiable): array
    {
        $channels = [];

        if (in_array('database', $this->channels, true)) {
            $channels[] = 'database';
        }

        if ($notifiable instanceof User && in_array('telegram', $this->channels, true) && $notifiable->telegramChatIdIgnoringMute() !== null) {
            $channels[] = TelegramChannel::class;
        }

        if ($notifiable instanceof User && $notifiable->email !== null && in_array('mail', $this->channels, true)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * The Telegram channel reads this to decide whether a muted chat still hears the alert at once
     * (critical rules, or rules told not to respect quiet hours).
     */
    public function isCritical(): bool
    {
        return $this->critical;
    }

    /**
     * The base Notification contract carries none of the channel methods, so the wrapper checks
     * for the one it is asked to delegate to — every alert the router carries defines all three.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        if (! method_exists($this->inner, 'toArray')) {
            return [];
        }

        return $this->inner->toArray($notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        if (! method_exists($this->inner, 'toMail')) {
            return new MailMessage;
        }

        return $this->inner->toMail($notifiable);
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        if (! method_exists($this->inner, 'toTelegram')) {
            return new TelegramMessage('');
        }

        return $this->inner->toTelegram($notifiable);
    }
}
