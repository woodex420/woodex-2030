<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Domain\System\Actions\HandleTelegramCommand;
use App\Domain\System\Enums\ChangeRequestStatus;
use App\Domain\System\Enums\ChangeRequestType;
use App\Domain\System\Enums\WebhookStatus;
use App\Domain\System\Models\ChangeRequest;
use App\Domain\System\Models\TelegramLink;
use App\Domain\System\Models\WebhookEvent;
use App\Domain\System\Telegram\TelegramCallbackBus;
use App\Domain\System\Telegram\TelegramCommandBus;
use App\Http\Controllers\Controller;
use App\Integrations\Telegram\Contracts\TelegramGateway;
use App\Integrations\Telegram\TelegramMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Telegram bot webhook (TGM-001…TGM-007): linking (/start CODE), change requests from mobile
 * (/idea, /issue), the command centre and inline buttons — every business command lives in the
 * TelegramCommandBus, one class per command, behind the linked user's own permissions.
 *
 * Hardened shell (agent security review, 17 Oct): authenticated by VerifyTelegramWebhook; every
 * update lands in the replay ledger first; business writes and the marker commit together inside
 * one transaction; replies go out only after the commit, and a failed reply never reruns the
 * business effect. A forged update (group routing, bot sender, forward, wrong addressee) is
 * ignored, never executed.
 */
final class TelegramWebhookController extends Controller
{
    /** Commands one chat may run per minute before the bot asks for a pause (TGM-007). */
    private const COMMANDS_PER_MINUTE = 30;

    public function __construct(
        private readonly TelegramGateway $telegram,
        private readonly TelegramCommandBus $bus,
        private readonly TelegramCallbackBus $callbacks,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $rawId = $request->input('update_id');
        $admitted = (is_int($rawId) && $rawId >= 0) || (is_string($rawId) && ctype_digit($rawId));
        if (! $admitted) {
            return response()->json(['ok' => true]);
        }
        $updateId = (string) $rawId;

        $event = WebhookEvent::query()->firstOrCreate([
            'provider' => 'telegram', 'external_id' => $updateId,
        ], [
            'type' => $request->has('message') ? 'message' : ($request->has('callback_query') ? 'callback' : 'other'),
            'payload' => $request->json()->all(),
            'signature_valid' => true,
            'status' => WebhookStatus::Received,
        ]);
        $duplicate = false;

        try {
            $reply = DB::transaction(function () use ($event, &$duplicate): ?array {
                $event = WebhookEvent::query()->lockForUpdate()->findOrFail($event->id);

                if (! $event->signature_valid || in_array($event->status, [WebhookStatus::Processed, WebhookStatus::Ignored], true)) {
                    $duplicate = true;

                    return null;
                }

                $message = $event->payload['message'] ?? null;
                $callback = $event->payload['callback_query'] ?? null;

                $reply = null;
                if (is_array($callback)) {
                    $reply = $this->callbackReply($callback);
                } elseif (is_array($message)) {
                    $reply = $this->commandReply($message);
                }

                $event->forceFill([
                    'status' => $reply === null ? WebhookStatus::Ignored : WebhookStatus::Processed,
                    'attempts' => $event->attempts + 1,
                    'processed_at' => now(),
                    'error' => null,
                ])->save();

                return $reply;
            });
        } catch (Throwable $exception) {
            Log::warning('Telegram command processing failed.', ['event_id' => $event->id, 'exception' => $exception::class]);

            // Preserve the trusted received row for the provider retry; do not expose an exception body.
            return response()->json(['ok' => false], 503);
        }

        if ($reply !== null) {
            try {
                if (! empty($reply['callback_id'])) {
                    $this->telegram->answerCallbackQuery((string) $reply['callback_id'], (string) $reply['text']);
                }

                $this->telegram->send((int) $reply['chat_id'], new TelegramMessage(TelegramMessage::escape((string) $reply['text'])));
            } catch (Throwable $exception) {
                // Business effects already committed. Never rerun them just because a reply failed.
                Log::warning('Telegram command reply failed.', ['event_id' => $event->id, 'exception' => $exception::class]);
            }
        }

        return response()->json(['ok' => true, 'duplicate' => $duplicate]);
    }

    /**
     * An inline button press (TGM-005): the answer goes to the button itself and to the chat, so it
     * survives the toast scrolling away.
     *
     * @param  array<string, mixed>  $callback
     * @return array{chat_id: int, text: string, callback_id: string}|null
     */
    private function callbackReply(array $callback): ?array
    {
        $callbackId = $callback['id'] ?? null;
        $chatId = self::positiveId($callback['message']['chat']['id'] ?? null);
        $senderId = self::positiveId($callback['from']['id'] ?? null);
        $data = $callback['data'] ?? null;

        if (! is_string($callbackId) || $callbackId === '' || $chatId === false || $senderId !== $chatId
            || ($callback['message']['chat']['type'] ?? null) !== 'private'
            || ($callback['from']['is_bot'] ?? false) || ! is_string($data) || $data === '') {
            return null;
        }

        $user = $this->linkedUserOrNull($chatId)?->user;
        if ($user === null) {
            return null;
        }
        $reply = $this->callbacks->dispatch($user, $data);

        return ['chat_id' => (int) $chatId, 'text' => $reply, 'callback_id' => $callbackId];
    }

    /**
     * A command from a private chat. The gate first (agent security review, 17 Oct): personal commands
     * need the actual human sender in their private chat — never a group's routing identity, a bot, or a
     * forward — then the studio's own commands run behind the linked user's permissions.
     *
     * @param  array<string, mixed>  $message
     * @return array{chat_id: int, text: string}|null
     */
    private function commandReply(array $message): ?array
    {
        $chatId = self::positiveId($message['chat']['id'] ?? null);
        $senderId = self::positiveId($message['from']['id'] ?? null);
        $text = $message['text'] ?? null;

        if ($chatId === false || $senderId === false || $chatId !== $senderId
            || ($message['chat']['type'] ?? null) !== 'private' || ($message['from']['is_bot'] ?? false)
            || isset($message['sender_chat']) || isset($message['via_bot'])
            || isset($message['forward_origin']) || isset($message['forward_from']) || isset($message['forward_from_chat'])
            || ! is_string($text) || ! str_starts_with(trim($text), '/') || mb_strlen($text) > 4096) {
            return null;
        }

        [$command, $argument] = array_pad(preg_split('/\s+/', trim($text), 2) ?: [], 2, '');
        $argument = trim((string) $argument);

        if (str_contains((string) $command, '@') && strcasecmp((string) Str::after((string) $command, '@'), ltrim((string) config('services.telegram.bot_username'), '@')) !== 0) {
            return null;
        }

        $command = Str::lower((string) Str::before((string) $command, '@'));

        if (RateLimiter::tooManyAttempts('telegram-command:'.$chatId, self::COMMANDS_PER_MINUTE)) {
            return ['chat_id' => $chatId, 'text' => 'Too many commands in one minute — give it a moment and try again.'];
        }

        RateLimiter::hit('telegram-command:'.$chatId);

        $user = $this->linkedUserOrNull($chatId)?->user;

        if (! in_array($command, ['/start', '/link'], true) && $user === null) {
            return ['chat_id' => $chatId, 'text' => TelegramCommandBus::OWNER_ONLY_MESSAGE];
        }

        $reply = match ($command) {
            '/start', '/link' => $this->link($message),
            '/idea', '/issue' => $this->changeRequest($chatId, $command === '/idea' ? ChangeRequestType::Idea : ChangeRequestType::Issue, $argument, (int) ($message['message_id'] ?? 0)),
            '/help' => $this->bus->help(),
            default => $this->bus->dispatch($user, $command, $argument),
        };

        return ['chat_id' => $chatId, 'text' => $reply];
    }

    private static function positiveId(mixed $value): int|false
    {
        return is_int($value) && $value > 0 ? $value : false;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function link(array $message): string
    {
        // One pairing action owns the user -> link lock order and code consumption for both entry points.
        return app(HandleTelegramCommand::class)->handle($message)['text'] ?? TelegramCommandBus::OWNER_ONLY_MESSAGE;
    }

    private function changeRequest(int $chatId, ChangeRequestType $type, string $text, int $messageId): string
    {
        $link = $this->linkedUserOrNull($chatId);

        if ($link === null) {
            return 'Please link this chat to your Woodex OS account first (profile → Link Telegram).';
        }

        if (mb_strlen($text) < 3) {
            return $type === ChangeRequestType::Idea
                ? 'Write your idea after the command, for example: /idea Add a kitchen filter to the portfolio'
                : 'Describe the problem after the command, for example: /issue The quote PDF shows the wrong phone number';
        }

        $request = ChangeRequest::create([
            'type' => $type,
            'title' => Str::limit($text, 120, '…'),
            'body' => $text,
            'status' => ChangeRequestStatus::New,
            'source' => 'telegram',
            'requested_by' => $link->user_id,
            'telegram_message_id' => $messageId ?: null,
        ]);

        return "Saved as {$request->reference()} ({$type->value}). You will be notified when it is planned.";
    }

    private function linkedUserOrNull(int $chatId): ?TelegramLink
    {
        return TelegramLink::query()->usable()->with('user')->where('chat_id', $chatId)->first();
    }
}
