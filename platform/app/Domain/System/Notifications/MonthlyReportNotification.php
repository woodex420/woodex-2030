<?php

declare(strict_types=1);

namespace App\Domain\System\Notifications;

use App\Domain\System\Models\MonthlyReport;
use App\Integrations\Telegram\TelegramChannel;
use App\Integrations\Telegram\TelegramMessage;
use App\Models\User;
use App\Settings\SalesSettings;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The monthly CRM report in one message (P8.5): Telegram to the owner, email when there is no
 * Telegram link, and an in-app copy that points at the archive under System → Automations. The
 * numbers are the saved payload, so the message and the screen can never disagree.
 */
final class MonthlyReportNotification extends Notification
{
    public function __construct(
        public readonly MonthlyReport $report,
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
            // The report still arrives when Telegram is not linked — by email.
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
            'kind' => 'report.monthly',
            'title' => 'Monthly report · '.$this->report->periodLabel(),
            'body' => $this->digest(),
            'url' => route('admin.automation.index'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Woodex OS monthly report — '.$this->report->periodLabel())
            ->greeting($this->report->periodLabel())
            ->lines($this->lines());

        $url = route('admin.automation.index');
        if (str_starts_with($url, 'https://')) {
            $mail->action('Open the archive', $url);
        }

        return $mail;
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $text = '📊 <b>Monthly report</b> · '.TelegramMessage::escape($this->report->periodLabel())."\n";
        $text .= str_repeat('—', 14)."\n";
        $text .= implode("\n", array_map(
            static fn (string $line): string => TelegramMessage::escape($line),
            $this->lines(),
        ));

        $buttons = [];
        $url = route('admin.automation.index');
        if (str_starts_with($url, 'https://')) {
            $buttons[] = [['text' => 'Open the archive', 'url' => $url]];
        }

        return new TelegramMessage($text, $buttons);
    }

    /**
     * The report as plain lines — one shape for mail, Telegram and the in-app copy.
     *
     * @return list<string>
     */
    private function lines(): array
    {
        $settings = app(SalesSettings::class);
        $rs = fn (int $minor): string => Money::ofMinor($minor)->format($settings->lakh_grouping);

        $funnel = $this->report->payload['funnel'] ?? [];
        $previous = $this->report->payload['funnel_previous'] ?? [];
        $money = $this->report->payload['money'] ?? [];
        $moneyPrevious = $this->report->payload['money_previous'] ?? [];
        $overdue = $this->report->payload['overdue'] ?? [];
        $chat = $this->report->payload['web_chat'] ?? [];

        $lines = [
            sprintf(
                '📥 %d enquiries · %d quotations sent · %d won (win rate %d%%)',
                (int) ($funnel['leads'] ?? 0),
                (int) ($funnel['quotes_sent'] ?? 0),
                (int) ($funnel['won'] ?? 0),
                (int) ($funnel['win_rate'] ?? 0),
            ),
            sprintf(
                '💰 Invoiced %s · Collected %s',
                $rs((int) ($money['invoiced_minor'] ?? 0)),
                $rs((int) ($money['collected_minor'] ?? 0)),
            ),
            sprintf(
                '⏳ Outstanding %s · %d invoice(s) overdue%s',
                $rs((int) ($money['outstanding_minor'] ?? 0)),
                (int) ($overdue['count'] ?? 0),
                (int) ($overdue['oldest_days'] ?? 0) > 0 ? ' (oldest '.(int) $overdue['oldest_days'].' days)' : '',
            ),
        ];

        if ((int) ($chat['threads'] ?? 0) > 0) {
            $lines[] = sprintf('💬 %d website chat thread(s), %d messages', (int) $chat['threads'], (int) $chat['messages']);
        }

        $top = $this->report->payload['top_sources'][0] ?? null;
        if (is_array($top) && (int) ($top['leads'] ?? 0) > 0) {
            $lines[] = sprintf('🥇 Top source: %s (%d leads)', (string) $top['label'], (int) $top['leads']);
        }

        // The comparison the owner actually reads for: are we moving?
        $leadsBefore = (int) ($previous['leads'] ?? 0);
        $collectedBefore = (int) ($moneyPrevious['collected_minor'] ?? 0);

        if ($leadsBefore > 0 || (int) ($funnel['leads'] ?? 0) > 0) {
            $lines[] = sprintf(
                '📈 vs month before: enquiries %d → %d · collected %s → %s',
                $leadsBefore,
                (int) ($funnel['leads'] ?? 0),
                $rs($collectedBefore),
                $rs((int) ($money['collected_minor'] ?? 0)),
            );
        }

        return $lines;
    }

    private function digest(): string
    {
        $headline = $this->report->headline();

        return sprintf(
            '%d enquiries · %d won · collected %s',
            $headline['leads'],
            $headline['won'],
            Money::ofMinor($headline['collected_minor'])->format(app(SalesSettings::class)->lakh_grouping),
        );
    }
}
