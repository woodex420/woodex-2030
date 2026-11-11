<?php

declare(strict_types=1);

namespace App\Domain\Projects\Notifications;

use App\Integrations\Telegram\TelegramMessage;
use App\Notifications\Concerns\SendsToDatabaseAndTelegram;
use Illuminate\Notifications\Notification;

/**
 * What the team is told while a project moves (PRJ-002/004): a stage changed, or a note from the site was shared
 * with the client. Bell always, Telegram when linked.
 */
final class ProjectNotification extends Notification
{
    use SendsToDatabaseAndTelegram;

    private const array TITLES = [
        'stage' => ['🏗', 'Project stage changed'],
        'shared' => ['📸', 'Update shared with the client'],
    ];

    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
        public readonly string $kind = 'stage',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'project.'.$this->kind,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
        ];
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        [$icon, $title] = self::TITLES[$this->kind] ?? ['🏗', 'Project'];

        return new TelegramMessage(
            $icon.' <b>'.TelegramMessage::escape($title).'</b>'."\n".TelegramMessage::escape($this->title)."\n"
            .TelegramMessage::escape($this->body),
            $this->openButton('Open project', $this->url),
        );
    }
}
