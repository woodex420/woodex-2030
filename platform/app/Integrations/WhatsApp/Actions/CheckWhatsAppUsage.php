<?php

declare(strict_types=1);

namespace App\Integrations\WhatsApp\Actions;

use App\Domain\Conversations\Enums\MessageDirection;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Conversations\Models\Message;
use App\Domain\System\Alerts\AlertRouter;
use App\Domain\System\Notifications\WhatsAppUsageNotification;
use App\Models\User;
use App\Settings\IntegrationSettings;

/**
 * WAP-008: the WhatsApp cost meter.
 *
 * Counts this month's successfully sent messages (free-form and template separately — Meta
 * prices them differently) and, when the studio has entered rates, turns them into rupees.
 * Crossing the monthly budget alerts whoever manages integrations, once per month. The
 * messages table is the source of truth — no separate rollup to keep in step.
 *
 * @return array{month: string, text: int, template: int, estimated_cost: int, budget: int, alerted: bool}
 */
final class CheckWhatsAppUsage
{
    public function __construct(
        private readonly IntegrationSettings $settings,
    ) {}

    /**
     * @return array{month: string, text: int, template: int, estimated_cost: int, budget: int, alerted: bool}
     */
    public function handle(): array
    {
        $month = now()->format('Y-m');

        $counts = Message::query()
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.channel', 'whatsapp')
            ->where('messages.direction', MessageDirection::Out->value)
            ->where('messages.type', '!=', 'note')
            ->where('messages.status', '!=', MessageStatus::Failed->value)
            ->whereBetween('messages.created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->groupBy('messages.type')
            ->selectRaw('messages.type, count(*) as c')
            ->pluck('c', 'messages.type');

        $text = (int) ($counts['text'] ?? 0);
        $template = (int) ($counts['template'] ?? 0);
        $cost = ($text * $this->settings->whatsapp_text_cost) + ($template * $this->settings->whatsapp_template_cost);

        $alerted = false;

        if (
            $this->settings->whatsapp_monthly_budget > 0
            && $cost >= $this->settings->whatsapp_monthly_budget
            && $this->settings->whatsapp_budget_alerted_month !== $month
        ) {
            $alerted = true;

            $recipients = User::query()
                ->with('telegramLink')
                ->where('is_active', true)
                ->permission('integrations.manage')
                ->get();

            if ($recipients->isNotEmpty()) {
                app(AlertRouter::class)->send('system.whatsapp_usage', $recipients, new WhatsAppUsageNotification($month, $text, $template, $cost, $this->settings->whatsapp_monthly_budget));
            }
        }

        if ($alerted) {
            // Remember the month so the alert fires once; a new month compares fresh.
            $this->settings->whatsapp_budget_alerted_month = $month;
            $this->settings->save();
        }

        return ['month' => $month, 'text' => $text, 'template' => $template, 'estimated_cost' => $cost, 'budget' => $this->settings->whatsapp_monthly_budget, 'alerted' => $alerted];
    }
}
