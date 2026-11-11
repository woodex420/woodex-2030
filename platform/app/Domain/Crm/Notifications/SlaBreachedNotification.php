<?php

declare(strict_types=1);

namespace App\Domain\Crm\Notifications;

use App\Domain\Crm\Models\Lead;
use App\Integrations\Telegram\TelegramChannel;
use App\Integrations\Telegram\TelegramMessage;
use App\Support\Phone;
use Illuminate\Notifications\Notification;

/**
 * A new lead was not contacted within the first-response target (CRM-010, TGM-002).
 */
final class SlaBreachedNotification extends Notification
{
    public function __construct(public readonly Lead $lead, public readonly bool $escalated = false) {}

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
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->escalated ? 'lead.sla_escalated' : 'lead.sla_breached',
            'title' => $this->escalated ? 'Still not contacted' : 'Response time missed',
            'body' => "{$this->lead->contact->name} ({$this->lead->ref}) has not been contacted yet · ".($this->lead->owner->name ?? 'unassigned'),
            'lead_id' => $this->lead->id,
            'url' => route('admin.leads.show', $this->lead),
        ];
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $lead = $this->lead;
        $minutes = $lead->sla_due_at !== null ? (int) $lead->sla_due_at->diffInMinutes(now(), true) : 0;

        $text = implode("\n", [
            ($this->escalated ? '🚨 <b>Still not contacted</b> · ' : '⏰ <b>Response time missed</b> · ').TelegramMessage::escape($lead->ref),
            '👤 '.TelegramMessage::escape($lead->contact->name).' · '.TelegramMessage::escape(Phone::display($lead->contact->phone)),
            '🧑‍💼 '.TelegramMessage::escape($lead->owner->name ?? 'Unassigned').($minutes > 0 ? " · {$minutes} min late" : ''),
            '📥 '.TelegramMessage::escape($lead->source->label()),
        ]);

        $buttons = [];
        $row = [];

        if ($whatsapp = Phone::whatsappUrl($lead->contact->whatsappNumber())) {
            $row[] = ['text' => 'WhatsApp client', 'url' => $whatsapp];
        }

        $url = route('admin.leads.show', $lead);
        if (str_starts_with($url, 'https://')) {
            $row[] = ['text' => 'Open lead', 'url' => $url];
        }

        if ($row !== []) {
            $buttons[] = $row;
        }

        return new TelegramMessage($text, $buttons);
    }
}
