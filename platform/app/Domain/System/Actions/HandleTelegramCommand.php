<?php

declare(strict_types=1);

namespace App\Domain\System\Actions;

use App\Domain\System\Enums\ChangeRequestStatus;
use App\Domain\System\Enums\ChangeRequestType;
use App\Domain\System\Models\ChangeRequest;
use App\Domain\System\Models\TelegramLink;
use App\Domain\System\Telegram\TelegramCommandBus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Personal commands require the actual human sender in their private chat, never group routing identity. */
final class HandleTelegramCommand
{
    /**
     * @param  array<string, mixed>  $message
     * @return array{chat_id: int, text: string}|null
     */
    public function handle(array $message): ?array
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
        if (str_contains($command, '@') && strcasecmp(Str::after($command, '@'), ltrim((string) config('services.telegram.bot_username'), '@')) !== 0) {
            return null;
        }
        $command = Str::lower(Str::before($command, '@'));
        $argument = trim($argument);

        return DB::transaction(function () use ($chatId, $command, $argument, $message): array {
            if (! in_array($command, ['/start', '/link'], true) && $this->linkedUserOrNull($chatId) === null) {
                return ['chat_id' => $chatId, 'text' => TelegramCommandBus::OWNER_ONLY_MESSAGE];
            }
            $reply = match ($command) {
                '/start', '/link' => $this->link($chatId, $argument, $message),
                '/help' => "Woodex OS bot\n/idea <text>: suggest an improvement\n/issue <text>: report a problem\n/help: this list\nImportant business writes and approvals are not available in this bot yet.",
                '/idea', '/issue' => $this->changeRequest($chatId, $command === '/idea' ? ChangeRequestType::Idea : ChangeRequestType::Issue, $argument, $message),
                default => $this->linkedUserOrNull($chatId) === null
                    ? 'This private chat is not linked to an active Woodex OS staff account. Open your profile in the admin and tap "Link Telegram".'
                    : 'This command is not available. No business operation was executed. Send /help for available commands.',
            };

            return ['chat_id' => $chatId, 'text' => $reply];
        });
    }

    /** @param array<string, mixed> $message */
    private function link(int $chatId, string $code, array $message): string
    {
        if ($code === '') {
            return $this->linkedUserOrNull($chatId) !== null
                ? 'You are already linked to Woodex OS. Send /help to see the commands.'
                : 'Welcome to Woodex OS. To link this private chat, open your profile in the admin, tap "Link Telegram" and send the code shown there: /start CODE';
        }
        $invalid = 'That code is invalid or expired. Create a new code from your profile in the admin.';
        if (! preg_match('/^[A-Za-z0-9]{8,16}$/', $code)) {
            return $invalid;
        }
        $candidate = TelegramLink::query()->where('link_code', Str::upper($code))->first();
        if ($candidate === null || $candidate->user_id === null) {
            return $invalid;
        }
        // Consistent lock order with profile code issuance: user, then link. Recheck the code under lock.
        $user = User::query()->telegramOwners()->whereKey($candidate->user_id)->lockForUpdate()->first();
        $link = TelegramLink::query()->whereKey($candidate->id)->where('link_code', Str::upper($code))
            ->where('link_code_expires_at', '>', now())->lockForUpdate()->first();
        if ($user === null || $link === null || $link->user_id !== $user->id) {
            return $invalid;
        }
        if (TelegramLink::query()->where('chat_id', $chatId)->whereKeyNot($link->id)->exists()) {
            return 'This chat is already linked. Unlink it from the existing staff profile before linking another account.';
        }
        $first = is_string($message['from']['first_name'] ?? null) ? $message['from']['first_name'] : '';
        $last = is_string($message['from']['last_name'] ?? null) ? $message['from']['last_name'] : '';
        $name = trim($first.' '.$last);
        $link->forceFill([
            'chat_id' => $chatId, 'chat_type' => 'private', 'title' => Str::limit($name, 120, ''),
            'username' => is_string($message['from']['username'] ?? null) ? Str::limit($message['from']['username'], 64, '') : null,
            'link_code' => null, 'link_code_expires_at' => null, 'is_active' => true, 'linked_at' => now(),
        ])->save();

        return "✅ Linked to Woodex OS as {$user->name}. You will receive alerts here. Send /help for commands.";
    }

    /** @param array<string, mixed> $message */
    private function changeRequest(int $chatId, ChangeRequestType $type, string $text, array $message): string
    {
        $link = $this->linkedUserOrNull($chatId);
        if ($link === null) {
            return 'Please link this private chat to an active Woodex OS staff account first (profile → Link Telegram).';
        }
        if (mb_strlen($text) < 3) {
            return 'Write a description after /'.$type->value.', for example: /'.$type->value.' Improve the quotation layout';
        }
        $messageId = self::positiveId($message['message_id'] ?? null);
        $request = ChangeRequest::query()->create([
            'type' => $type, 'title' => Str::limit($text, 120, '…'), 'body' => $text,
            'status' => ChangeRequestStatus::New, 'source' => 'telegram', 'requested_by' => $link->user_id,
            'telegram_message_id' => $messageId === false ? null : $messageId,
        ]);

        return "Saved as {$request->reference()} ({$type->value}). You will be notified when it is planned.";
    }

    private function linkedUserOrNull(int $chatId): ?TelegramLink
    {
        $candidate = TelegramLink::query()->where('chat_id', $chatId)->first();
        if ($candidate === null || $candidate->user_id === null) {
            return null;
        }
        $user = User::query()->telegramOwners()->whereKey($candidate->user_id)->lockForUpdate()->first();
        if ($user === null) {
            return null;
        }

        return TelegramLink::query()->whereKey($candidate->id)->where('chat_id', $chatId)->where('user_id', $user->id)
            ->where('is_active', true)->where(fn ($query) => $query->whereNull('chat_type')->orWhere('chat_type', 'private'))
            ->lockForUpdate()->first();
    }

    private static function positiveId(mixed $value): int|false
    {
        if (! is_int($value) && (! is_string($value) || ! ctype_digit($value))) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    }
}
