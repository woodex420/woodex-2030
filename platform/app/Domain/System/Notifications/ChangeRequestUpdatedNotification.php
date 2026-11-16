<?php

declare(strict_types=1);

namespace App\Domain\System\Notifications;

use App\Domain\System\Enums\ChangeRequestStatus;
use App\Domain\System\Models\ChangeRequest;
use App\Integrations\Telegram\TelegramMessage;
use App\Models\User;
use App\Notifications\Concerns\SendsToDatabaseAndTelegram;
use Illuminate\Notifications\Notification;

/**
 * "Your idea was accepted" (SYS-010): the answer to /idea and /issue, on the bell and on Telegram.
 */
final class ChangeRequestUpdatedNotification extends Notification
{
    use SendsToDatabaseAndTelegram;

    public function __construct(
        public readonly ChangeRequest $request,
        public readonly User $by,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'change_request.updated',
            'title' => $this->request->reference().' '.$this->request->status->label(),
            'body' => $this->summary(),
            'change_request_id' => $this->request->id,
            'ref' => $this->request->reference(),
            'url' => route('admin.system.change-requests'),
        ];
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $lines = [
            $this->icon().' <b>'.TelegramMessage::escape($this->request->reference()).' '.TelegramMessage::escape($this->request->status->label()).'</b>',
            TelegramMessage::escape($this->request->title),
            'by '.TelegramMessage::escape($this->by->name),
        ];

        if ($this->request->resolution_note !== null && $this->request->resolution_note !== '') {
            $lines[] = '📝 '.TelegramMessage::escape($this->request->resolution_note);
        }

        return new TelegramMessage(implode("\n", $lines), $this->openButton('Open the board', route('admin.system.change-requests')));
    }

    private function icon(): string
    {
        return match ($this->request->status) {
            ChangeRequestStatus::Done => '✅',
            ChangeRequestStatus::Rejected => '🚫',
            default => '💡',
        };
    }

    private function summary(): string
    {
        return trim($this->request->title.' · moved by '.$this->by->name
            .($this->request->resolution_note !== null && $this->request->resolution_note !== '' ? ' · '.$this->request->resolution_note : ''));
    }
}
