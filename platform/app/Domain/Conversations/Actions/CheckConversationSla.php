<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Actions;

use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Notifications\ConversationSlaBreachedNotification;
use App\Domain\System\Alerts\AlertRouter;
use App\Models\User;
use App\Settings\NotificationSettings;
use App\Settings\SalesSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * CNV-007: flag WhatsApp conversations whose reply target has passed and alert the assignee
 * plus the escalation roles (in-app + Telegram) — the mirror of the lead first-response SLA.
 * The second level (still unanswered after the escalation delay) reaches the final roles.
 * Every step is claimed atomically, so overlapping scheduler runs never alert twice.
 */
final class CheckConversationSla
{
    public function __construct(
        private readonly NotificationSettings $settings,
        private readonly SalesSettings $sales,
    ) {}

    /** @return int number of conversations newly flagged */
    public function handle(): int
    {
        $flagged = 0;

        Conversation::query()
            ->where('channel', 'whatsapp')
            ->where('status', ConversationStatus::Open->value)
            ->whereNotNull('reply_due_at')
            ->whereNull('reply_sla_breached_at')
            ->where('reply_due_at', '<=', now())
            ->with(['contact', 'assignee.telegramLink'])
            ->chunkById(100, function (Collection $conversations) use (&$flagged): void {
                foreach ($conversations as $conversation) {
                    $claimed = Conversation::query()->whereKey($conversation->id)->whereNull('reply_sla_breached_at')->update(['reply_sla_breached_at' => now()]);

                    if ($claimed === 0) {
                        continue;
                    }

                    $recipients = $this->recipients($conversation, $this->settings->unassigned_lead_roles);

                    if ($recipients->isNotEmpty()) {
                        app(AlertRouter::class)->send('conversation.sla', $recipients, new ConversationSlaBreachedNotification($conversation));
                    }

                    $flagged++;
                }
            });

        return $flagged;
    }

    /** @return int number of conversations escalated */
    public function escalate(): int
    {
        $escalated = 0;

        Conversation::query()
            ->where('channel', 'whatsapp')
            ->where('status', ConversationStatus::Open->value)
            ->whereNotNull('reply_sla_breached_at')
            ->whereNull('reply_sla_escalated_at')
            ->where('reply_sla_breached_at', '<=', now()->subMinutes($this->sales->sla_escalation_minutes))
            ->where(fn (Builder $query) => $query->whereNull('reply_due_at')->orWhere('reply_due_at', '<=', now()))
            ->with(['contact', 'assignee.telegramLink'])
            ->chunkById(100, function (Collection $conversations) use (&$escalated): void {
                foreach ($conversations as $conversation) {
                    $claimed = Conversation::query()->whereKey($conversation->id)->whereNull('reply_sla_escalated_at')->update(['reply_sla_escalated_at' => now()]);

                    if ($claimed === 0) {
                        continue;
                    }

                    $recipients = $this->recipients($conversation, $this->settings->sla_final_roles);

                    if ($recipients->isNotEmpty()) {
                        app(AlertRouter::class)->send('conversation.sla', $recipients, new ConversationSlaBreachedNotification($conversation, escalated: true));
                    }

                    $escalated++;
                }
            });

        return $escalated;
    }

    /**
     * @param  list<string>  $roles
     * @return Collection<int, User>
     */
    private function recipients(Conversation $conversation, array $roles): Collection
    {
        $users = User::query()
            ->with('telegramLink')
            ->where('is_active', true)
            ->whereHas('roles', fn (Builder $q) => $q->whereIn('name', $roles))
            ->get();

        if ($conversation->assignee !== null && $conversation->assignee->is_active) {
            $users->push($conversation->assignee->refresh());
        }

        return $users->unique('id')->values();
    }
}
