<?php

declare(strict_types=1);

namespace App\Integrations\Telegram;

use App\Domain\System\Models\IntegrationLog;
use App\Domain\System\Models\TelegramLink;
use App\Domain\System\Models\WebhookEvent;
use App\Integrations\Telegram\Contracts\TelegramGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Slice L: the connection-health ladder for Telegram. "Not connected" helps nobody — each step of the
 * chain is checked where the truth lives (server config, Telegram itself via the gateway, the webhook
 * ledger, the outbound log) and the failing step is named with its fix. The two API reads are cached
 * for a minute so the 5-second pairing poll never hammers Telegram or fills the integration log.
 *
 * @phpstan-type HealthStep array{key: string, label: string, ok: bool, detail: string, hint: ?string}
 */
final class TelegramHealth
{
    public const STATUS_CACHE_KEY = 'telegram:connection-status';

    public function __construct(
        private readonly TelegramGateway $gateway,
        private readonly TelegramPairing $pairing,
    ) {}

    /**
     * @return list<HealthStep>
     */
    public function steps(?TelegramLink $link): array
    {
        $tokenSet = filled(config('services.telegram.bot_token'));
        $secretSet = filled(config('services.telegram.webhook_secret'));
        $username = $this->pairing->botUsername();

        $steps = [
            [
                'key' => 'token',
                'label' => 'Bot token',
                'ok' => $tokenSet,
                'detail' => $tokenSet ? 'TELEGRAM_BOT_TOKEN is set on the server.' : 'TELEGRAM_BOT_TOKEN is not set on the server.',
                'hint' => $tokenSet ? null : 'An administrator adds the token from @BotFather to the server .env.',
            ],
            [
                'key' => 'secret',
                'label' => 'Webhook secret',
                'ok' => $secretSet,
                'detail' => $secretSet ? 'TELEGRAM_WEBHOOK_SECRET is set.' : 'TELEGRAM_WEBHOOK_SECRET is not set — inbound updates are refused.',
                'hint' => $secretSet ? null : 'An administrator sets it in the server .env, then reconnects the webhook in System → Integrations.',
            ],
            [
                'key' => 'username',
                'label' => 'Pairing link target',
                'ok' => $username !== null,
                'detail' => $username !== null ? "Pairing links and the QR open @{$username}." : 'TELEGRAM_BOT_USERNAME is empty — a pairing link would open no bot at all.',
                'hint' => $username !== null ? null : 'An administrator sets TELEGRAM_BOT_USERNAME to the bot\'s real username.',
            ],
        ];

        if (! $tokenSet) {
            $steps[] = ['key' => 'api', 'label' => 'Bot reachable', 'ok' => false, 'detail' => 'Cannot check until the token is set.', 'hint' => null];
            $steps[] = ['key' => 'webhook', 'label' => 'Webhook', 'ok' => false, 'detail' => 'Cannot check until the token is set.', 'hint' => null];
        } else {
            $status = $this->connectionStatus($username);
            $apiOk = (bool) ($status['api_ok'] ?? false);
            $bot = is_string($status['bot_username'] ?? null) ? $status['bot_username'] : null;

            $steps[] = [
                'key' => 'api',
                'label' => 'Bot reachable',
                'ok' => $apiOk,
                'detail' => $apiOk
                    ? 'Telegram answered'.($bot !== null ? " as @{$bot}" : '').'.'
                    : 'Telegram did not answer — the token was rejected or the server cannot reach api.telegram.org.',
                'hint' => $apiOk ? null : 'Check the token in @BotFather and the server\'s outbound network.',
            ];

            if ($apiOk && $username !== null && ! (bool) ($status['bot_matches'] ?? false)) {
                $steps[] = [
                    'key' => 'bot_match',
                    'label' => 'Token and link agree',
                    'ok' => false,
                    'detail' => "The token belongs to @{$bot} but pairing links open @{$username} — the QR would open the wrong bot.",
                    'hint' => 'Set TELEGRAM_BOT_USERNAME to @'.($bot ?? 'your bot').'.',
                ];
            }

            $webhookSet = (bool) ($status['webhook_set'] ?? false);
            $webhookMatches = (bool) ($status['webhook_matches'] ?? false);
            $lastError = trim((string) ($status['last_error'] ?? ''));
            $webhookDetail = match (true) {
                $webhookSet && $webhookMatches => 'Telegram delivers updates to this site\'s webhook.',
                ! $webhookSet => 'No webhook is registered with Telegram — /start never reaches this server.',
                default => 'The webhook registered with Telegram points at a different address.',
            };
            if ($apiOk && $lastError !== '') {
                $webhookDetail .= ' Telegram\'s last delivery error: '.$lastError;
            }

            $steps[] = [
                'key' => 'webhook',
                'label' => 'Webhook',
                'ok' => $apiOk && $webhookSet && $webhookMatches,
                'detail' => $apiOk ? $webhookDetail : 'Cannot check until the bot answers.',
                'hint' => $apiOk && ! ($webhookSet && $webhookMatches) ? 'An administrator uses Connect webhook in System → Integrations.' : null,
            ];
        }

        $lastUpdate = $this->lastUpdateAt();
        $steps[] = [
            'key' => 'updates',
            'label' => 'Updates arriving',
            'ok' => $lastUpdate !== null && $lastUpdate->greaterThan(now()->subDay()),
            'detail' => match (true) {
                $lastUpdate === null => 'No update has ever reached this server.',
                $lastUpdate->greaterThan(now()->subDay()) => 'Last update received '.$lastUpdate->diffForHumans().'.',
                default => 'Last update received '.$lastUpdate->diffForHumans().' — nothing in the last 24 hours.',
            },
            'hint' => $lastUpdate !== null && $lastUpdate->greaterThan(now()->subDay()) ? null : 'If the webhook step passes, send the bot any message — silence here then means Telegram is not delivering.',
        ];

        $steps[] = $this->chatStep($link);

        return $steps;
    }

    public function lastUpdateAt(): ?CarbonImmutable
    {
        $at = WebhookEvent::query()->where('provider', 'telegram')->max('created_at');

        return $at === null ? null : CarbonImmutable::parse((string) $at);
    }

    /**
     * The last message this linked chat sent the bot, read from the webhook ledger.
     */
    public function lastMessageAt(?TelegramLink $link): ?CarbonImmutable
    {
        if ($link === null || ! TelegramLink::query()->usable()->whereKey($link->id)->exists()) {
            return null;
        }

        $at = WebhookEvent::query()
            ->where('provider', 'telegram')
            ->where('payload->message->chat->id', $link->chat_id)
            ->max('created_at');

        return $at === null ? null : CarbonImmutable::parse((string) $at);
    }

    /**
     * @return HealthStep
     */
    private function chatStep(?TelegramLink $link): array
    {
        if ($link === null || ! TelegramLink::query()->usable()->whereKey($link->id)->exists()) {
            return ['key' => 'chat', 'label' => 'Your Telegram', 'ok' => false, 'detail' => 'Not linked yet — use the pairing panel above.', 'hint' => null];
        }

        if ($this->botIsBlocked($link->chat_id)) {
            return [
                'key' => 'chat',
                'label' => 'Your Telegram',
                'ok' => false,
                'detail' => 'Telegram reports this chat has blocked the bot — alerts cannot be delivered.',
                'hint' => 'Open the bot chat on your phone and tap Restart (unblock), then send any message.',
            ];
        }

        $who = $link->username !== null && $link->username !== '' ? '@'.$link->username : 'chat '.$link->chat_id;

        return ['key' => 'chat', 'label' => 'Your Telegram', 'ok' => true, 'detail' => "Linked as {$who}.", 'hint' => null];
    }

    /**
     * A 403 "bot was blocked by the user" in the outbound log, with no successful send to that chat since.
     */
    private function botIsBlocked(int $chatId): bool
    {
        $blocked = IntegrationLog::query()
            ->where('integration', 'telegram')
            ->where('action', 'sendMessage')
            ->where('ok', false)
            ->where('request->chat_id', $chatId)
            ->latest('id')
            ->first();

        if ($blocked === null || ! str_contains(strtolower((string) ($blocked->response['description'] ?? '')), 'blocked')) {
            return false;
        }

        return ! IntegrationLog::query()
            ->where('integration', 'telegram')
            ->where('action', 'sendMessage')
            ->where('ok', true)
            ->where('request->chat_id', $chatId)
            ->where('id', '>', $blocked->id)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionStatus(?string $username): array
    {
        return Cache::remember(self::STATUS_CACHE_KEY, 60, fn (): array => $this->gateway->connectionStatus(
            route('webhooks.telegram'),
            $username ?? '',
        ));
    }
}
