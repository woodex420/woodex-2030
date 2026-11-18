<?php

declare(strict_types=1);

namespace App\Domain\Crm\Notifications;

use App\Domain\Crm\Models\SiteVisit;
use App\Integrations\Telegram\TelegramMessage;
use App\Notifications\Concerns\SendsToDatabaseAndTelegram;
use App\Support\Phone;
use Illuminate\Notifications\Notification;

/**
 * Staff reminder for a site visit (CRM-008): the day before and 2 hours before.
 */
final class VisitReminderNotification extends Notification
{
    use SendsToDatabaseAndTelegram;

    public function __construct(public readonly SiteVisit $visit, public readonly string $when) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'visit.reminder',
            'title' => 'Site visit '.$this->when,
            'body' => ($this->visit->contact->name ?? 'Client').' · '.$this->visit->scheduled_at->format('D j M, g:i a')
                .($this->visit->address ? ' · '.$this->visit->address : ''),
            'visit_id' => $this->visit->id,
            'url' => route('admin.leads.show', $this->visit->lead_id),
        ];
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $visit = $this->visit;
        $contact = $visit->contact;
        $map = $visit->map_url ?: ($visit->address ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($visit->address) : null);

        $buttons = $this->openButton('Open lead', route('admin.leads.show', $visit->lead_id));
        if ($map !== null) {
            $buttons[] = [['text' => 'Directions', 'url' => $map]];
        }

        return new TelegramMessage(implode("\n", array_filter([
            '📍 <b>Site visit '.TelegramMessage::escape($this->when).'</b> · '.TelegramMessage::escape($visit->scheduled_at->format('D j M, g:i a')),
            '👤 '.TelegramMessage::escape(($contact->name ?? 'Client').' · '.Phone::display($contact?->phone)),
            $visit->address ? '🏠 '.TelegramMessage::escape($visit->address) : null,
        ])), $buttons);
    }
}
