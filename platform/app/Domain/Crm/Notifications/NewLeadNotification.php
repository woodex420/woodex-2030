<?php

declare(strict_types=1);

namespace App\Domain\Crm\Notifications;

use App\Domain\Crm\Models\Lead;
use App\Domain\System\Telegram\TelegramCallbackBus;
use App\Integrations\Telegram\TelegramChannel;
use App\Integrations\Telegram\TelegramMessage;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

final class NewLeadNotification extends Notification
{
    public function __construct(
        public readonly Lead $lead,
        public readonly bool $isNewLead = true,
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
            // P8.5: a team member without a Telegram link still hears about the enquiry — by email.
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
            'kind' => $this->isNewLead ? 'lead.new' : 'lead.returning',
            'title' => $this->isNewLead ? 'New lead' : 'Returning enquiry',
            'body' => $this->summary(),
            'lead_id' => $this->lead->id,
            'ref' => $this->lead->ref,
            'url' => route('admin.leads.show', $this->lead),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lead = $this->lead;
        $adminUrl = route('admin.leads.show', $lead);

        $mail = (new MailMessage)
            ->subject(($this->isNewLead ? 'New lead ' : 'Returning enquiry ').$lead->ref.' — '.$lead->contact->name)
            ->line($this->summary())
            ->line('Respond by '.($lead->sla_due_at?->timezone('Asia/Karachi')->format('D j M, g:i a') ?? '—'));

        if (str_starts_with($adminUrl, 'https://')) {
            $mail->action('Open in Woodex OS', $adminUrl);
        }

        return $mail;
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $lead = $this->lead;
        $contact = $lead->contact;

        $lines = [
            ($this->isNewLead ? '🔔 <b>New lead</b>' : '↩️ <b>Returning enquiry</b>').' · '.TelegramMessage::escape($lead->ref),
            '👤 '.TelegramMessage::escape($contact->name).' · '.TelegramMessage::escape(Phone::display($contact->phone)),
            '🏷 '.TelegramMessage::escape($lead->business_line->label()).($lead->service ? ' · '.TelegramMessage::escape(Str::headline($lead->service)) : ''),
            '📍 '.TelegramMessage::escape(trim(($lead->area ?? '').' '.($lead->city ?? '')) ?: '—'),
            '📥 '.TelegramMessage::escape($lead->source->label()),
        ];

        if ($lead->message) {
            $lines[] = '💬 '.TelegramMessage::escape(Str::limit($lead->message, 300));
        }

        if ($lead->sla_due_at !== null) {
            $lines[] = '⏱ Respond by '.$lead->sla_due_at->timezone('Asia/Karachi')->format('D j M, g:i a');
        }

        $buttons = [];
        $row = [];

        // One tap for the most common first action: take the lead (TGM-005). Callback buttons expire after
        // 24 h, so an old alert can never act on a lead that has since been given to someone else.
        $row[] = ['text' => 'Assign to me', 'callback_data' => TelegramCallbackBus::button('assign', $lead->id)];

        $whatsapp = Phone::whatsappUrl($contact->whatsappNumber());
        if ($whatsapp !== null) {
            $row[] = ['text' => 'WhatsApp', 'url' => $whatsapp];
        }

        $adminUrl = route('admin.leads.show', $lead);
        if (str_starts_with($adminUrl, 'https://')) {
            $row[] = ['text' => 'Open in Woodex OS', 'url' => $adminUrl];
        }

        $buttons[] = $row;

        return new TelegramMessage(implode("\n", $lines), $buttons);
    }

    private function summary(): string
    {
        $lead = $this->lead;

        return trim(sprintf(
            '%s · %s%s · %s',
            $lead->contact->name,
            $lead->business_line->label(),
            $lead->service ? ' ('.Str::headline($lead->service).')' : '',
            $lead->source->label(),
        ));
    }
}
