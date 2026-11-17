<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Actions;

use App\Domain\Conversations\Models\Conversation;
use App\Settings\SalesSettings;

/**
 * CNV-007: arm the reply target when a WhatsApp message arrives.
 *
 * The first reply gets the longer target (nobody has answered this client at all yet);
 * every later inbound message re-arms the shorter one. A staff reply clears the clock.
 * Website chats are deliberately not armed — the lead SLA and the live-chat bell already
 * cover them, and double alerts would only train the team to ignore alerts.
 */
final class SetConversationReplyDue
{
    public function __construct(
        private readonly SalesSettings $settings,
    ) {}

    public function handle(Conversation $conversation): void
    {
        $minutes = $conversation->messages()
            ->where('direction', 'out')
            ->where('type', '!=', 'note')
            ->exists()
            ? $this->settings->conversation_next_reply_minutes
            : $this->settings->conversation_first_reply_minutes;

        $conversation->forceFill(['reply_due_at' => now()->addMinutes($minutes)])->save();
    }
}
