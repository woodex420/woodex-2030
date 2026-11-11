<?php

declare(strict_types=1);

namespace App\Domain\Bot\Actions;

use App\Domain\Bot\BotGuardrails;
use App\Domain\Bot\Contracts\BotProvider;
use App\Domain\Bot\Models\BotKnowledge;
use App\Domain\Bot\Models\BotUsage;
use App\Domain\Bot\Providers\AnthropicProvider;
use App\Domain\Bot\Providers\GeminiProvider;
use App\Domain\Bot\Providers\KeylessProvider;
use App\Domain\Bot\Providers\OpenAIProvider;
use App\Domain\Conversations\Enums\Channel;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Enums\MessageDirection;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Conversations\Enums\MessageType;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\Message;
use App\Domain\System\Alerts\AlertRouter;
use App\Domain\System\Notifications\BotHandoffNotification;
use App\Models\User;
use App\Settings\BotSettings;
use App\Settings\BusinessSettings;
use Carbon\CarbonImmutable;

/**
 * The assistant's one move (BOT-002/006/007/008/010): a visitor message came in on a live
 * thread — decide whether the bot answers, answer safely, and hand over when it should not.
 *
 * Order of the checks is the order the studio would want them asked: is the bot on at all, is
 * this the hour it works, has the thread had its share of bot replies, has the day had its
 * tokens, does the chosen brain have a key. Only then does anything get thought — and whatever
 * comes back passes the guardrails before the visitor ever sees it.
 */
final class ReplyWithBot
{
    public function __construct(
        private readonly BotSettings $settings,
        private readonly BusinessSettings $business,
        private readonly BotGuardrails $guardrails,
    ) {}

    /**
     * @return Message|null the bot's reply, or null when a human is the right answer and none was sent here
     */
    public function handle(Conversation $conversation): ?Message
    {
        if (! $this->settings->enabled || $conversation->channel !== Channel::WebChat) {
            return null;
        }

        $provider = $this->provider();

        $usage = BotUsage::today($conversation->channel->value, $provider->name());
        $usage->increment('requests');

        // BOT-010: during studio hours the team is at the desks — the bot stays for the evening.
        if ($this->settings->outside_hours_only && $this->business->hours()->isOpen(CarbonImmutable::now())) {
            $usage->increment('handoffs');
            $this->handOff($conversation, 'studio hours — the team answers');

            return null;
        }

        // BOT-008: one thread does not become a chat with a machine.
        if ($this->settings->conversation_turn_limit > 0 && $this->botReplies($conversation) >= $this->settings->conversation_turn_limit) {
            $usage->increment('handoffs');
            $this->handOff($conversation, 'the thread reached its bot-reply limit');

            return null;
        }

        // BOT-008: the day's tokens are the studio's money; when they are gone, honesty is free.
        if ($this->settings->daily_token_cap > 0 && ($usage->tokens_in + $usage->tokens_out) >= $this->settings->daily_token_cap && $provider->name() !== 'none') {
            $usage->increment('handoffs');
            $this->handOff($conversation, "the day's token cap was reached");

            return null;
        }

        if ($provider->name() === 'none' || ! $provider->isConfigured()) {
            // Keyless mode — chosen or forced by a missing key: an honest reply, and a human
            // hears about it. The machinery works; the thinking waits for a key.
            $usage->increment('handoffs');
            $this->handOff($conversation, 'no provider key configured');

            return $this->store($conversation, (new KeylessProvider)->complete('', [])->text, []);
        }

        $knowledge = BotKnowledge::retrieve((string) ($this->lastVisitorMessage($conversation)->body ?? ''));
        $context = array_map(static fn (BotKnowledge $entry): string => $entry->title.' — '.preg_replace('/\s+/u', ' ', $entry->content), $knowledge);

        $reply = $provider->complete(
            $this->settings->prompt().($context === [] ? '' : "\n\nKNOWLEDGE:\n".implode("\n\n", $context)),
            $this->history($conversation),
        );

        if (! $reply->ok) {
            report(new \RuntimeException('Bot provider failed: '.($reply->error ?? 'unknown')));

            $usage->increment('handoffs');
            $this->handOff($conversation, 'the model did not answer');

            return null;
        }

        // BOT-007: the guardrails run on every reply, model or not.
        $violations = $this->guardrails->violations($reply->text, $context);

        if ($violations !== []) {
            $usage->increment('guardrail_hits');
            $usage->increment('handoffs');
            $this->handOff($conversation, 'guardrails: '.implode('; ', $violations));

            return $this->store($conversation, $this->guardrails->safeReply(), ['guardrails' => $violations]);
        }

        $usage->increment('replies');
        $usage->increment('tokens_in', $reply->tokensIn);
        $usage->increment('tokens_out', $reply->tokensOut);

        return $this->store($conversation, $reply->text, ['tokens' => $reply->tokens()]);
    }

    /** The brain the studio chose — falling back to the keyless one when it cannot think. */
    private function provider(): BotProvider
    {
        $provider = match ($this->settings->provider) {
            'anthropic' => AnthropicProvider::class,
            'openai' => OpenAIProvider::class,
            'gemini' => GeminiProvider::class,
            default => KeylessProvider::class,
        };

        $resolved = app($provider);

        return $resolved instanceof BotProvider ? $resolved : app(KeylessProvider::class);
    }

    /** @return list<array{role: 'user'|'assistant', content: string}> */
    private function history(Conversation $conversation): array
    {
        return $conversation->messages()
            ->whereIn('type', [MessageType::Text->value])
            ->where('status', '!=', MessageStatus::Failed->value)
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->map(static fn (Message $message): array => [
                'role' => $message->direction === MessageDirection::In ? 'user' : 'assistant',
                'content' => (string) $message->body,
            ])
            ->values()
            ->all();
    }

    private function botReplies(Conversation $conversation): int
    {
        return $conversation->messages()
            ->where('direction', MessageDirection::Out->value)
            ->where('type', '!=', 'note')
            ->where('meta->bot', true)
            ->count();
    }

    private function lastVisitorMessage(Conversation $conversation): ?Message
    {
        return $conversation->messages()
            ->where('direction', MessageDirection::In->value)
            ->latest('id')
            ->first();
    }

    /** @param  array<string, mixed>  $meta */
    private function store(Conversation $conversation, string $body, array $meta): Message
    {
        /** @var Message $message */
        $message = $conversation->messages()->create([
            'direction' => MessageDirection::Out,
            'type' => MessageType::Text,
            'body' => $body,
            'status' => MessageStatus::Sent,
            'meta' => ['bot' => true, ...$meta],
            'sent_at' => now(),
        ]);

        $conversation->touchLastMessage(MessageDirection::Out, $body);

        return $message;
    }

    /** BOT-006: the thread waits for a person, and the people who should hear about it, do. */
    private function handOff(Conversation $conversation, string $reason): void
    {
        if ($conversation->status !== ConversationStatus::Pending) {
            $conversation->forceFill(['status' => ConversationStatus::Pending->value])->save();
        }

        $roles = $this->settings->handoff_roles;

        $recipients = User::query()
            ->with('telegramLink')
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $roles))
            ->get();

        if ($recipients->isNotEmpty()) {
            app(AlertRouter::class)->send('chat.handoff', $recipients, new BotHandoffNotification($conversation, $reason));
        }
    }
}
