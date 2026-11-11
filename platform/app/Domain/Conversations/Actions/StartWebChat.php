<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Actions;

use App\Domain\Conversations\Enums\Channel;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Enums\MessageDirection;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Conversations\Enums\MessageType;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\Message;
use App\Settings\BusinessSettings;

/**
 * Opens (or resumes) a web chat thread (P8.5). The widget's token is its only credential: a
 * returning visitor keeps their thread and everything the team already said. A token the studio
 * does not know (a wiped database, a stale localStorage) is adopted rather than refused — the
 * thread starts fresh, the visitor never sees an error. The first turn is the studio's greeting:
 * a conversation never opens with a form.
 */
final class StartWebChat
{
    private const STATE_ASKED_NAME = 'asked_name';

    public function __construct(private readonly BusinessSettings $business) {}

    public function handle(?string $token): Conversation
    {
        $adopt = null;

        if ($token !== null && $token !== '' && preg_match('/^[A-Za-z0-9_-]{10,48}$/', $token) === 1) {
            $existing = Conversation::query()
                ->where('channel', Channel::WebChat->value)
                ->where('widget_token', $token)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $adopt = $token;
        }

        $conversation = Conversation::query()->create([
            'channel' => Channel::WebChat->value,
            'status' => ConversationStatus::Open->value,
            'widget_token' => $adopt ?? str()->random(40),
            'widget_state' => self::STATE_ASKED_NAME,
            'unread_count' => 0,
        ]);

        $this->bot($conversation, 'Assalam o Alaikum! 👋 Welcome to '.$this->business->brand_name.'.');
        $this->bot($conversation, "What's your name?");

        return $conversation;
    }

    /** The rule-based intake's voice — an Out message no human sent, so the inbox can tell them apart. */
    public function bot(Conversation $conversation, string $body): Message
    {
        /** @var Message $message */
        $message = $conversation->messages()->create([
            'direction' => MessageDirection::Out,
            'type' => MessageType::Text,
            'body' => $body,
            'status' => MessageStatus::Sent,
            'meta' => ['bot' => true],
            'sent_at' => now(),
        ]);

        $conversation->touchLastMessage(MessageDirection::Out, $body);

        return $message;
    }
}
