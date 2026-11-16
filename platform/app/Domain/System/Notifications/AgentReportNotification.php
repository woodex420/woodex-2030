<?php

declare(strict_types=1);

namespace App\Domain\System\Notifications;

use App\Integrations\Telegram\TelegramChannel;
use App\Integrations\Telegram\TelegramMessage;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One shape for the dormant agents' digests (P8.5): the SEO health check and the social post
 * ideas are both "a short list of plain lines for the owner". Telegram when linked, email when
 * not, an in-app copy always — the same routing every owner notification uses.
 */
final class AgentReportNotification extends Notification
{
    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly array $lines,
        public readonly string $url,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (method_exists($notifiable, 'telegramChatIdIgnoringMute') && $notifiable->telegramChatIdIgnoringMute() !== null) {
            $channels[] = TelegramChannel::class;
        } elseif ($notifiable instanceof User && $notifiable->email !== null) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => implode("\n", $this->lines),
            'url' => $this->url,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title.' — Woodex OS')
            ->greeting($this->title)
            ->lines($this->lines);

        if (str_starts_with($this->url, 'https://')) {
            $mail->action('Open in Woodex OS', $this->url);
        }

        return $mail;
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $text = '🤖 <b>'.TelegramMessage::escape($this->title).'</b>'."\n";
        $text .= str_repeat('—', 14)."\n";
        $text .= implode("\n", array_map(
            static fn (string $line): string => TelegramMessage::escape($line),
            $this->lines,
        ));

        $buttons = [];
        if (str_starts_with($this->url, 'https://')) {
            $buttons[] = [['text' => 'Open in Woodex OS', 'url' => $this->url]];
        }

        return new TelegramMessage($text, $buttons);
    }
}
