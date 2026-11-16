<?php

declare(strict_types=1);

namespace App\Integrations\Telegram\Clients;

use App\Domain\System\Models\IntegrationLog;
use App\Integrations\Telegram\Contracts\TelegramGateway;
use App\Integrations\Telegram\TelegramMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class TelegramBotApi implements TelegramGateway
{
    public function __construct(
        private readonly ?string $token,
        private readonly string $apiBase = 'https://api.telegram.org',
    ) {}

    public function isConfigured(): bool
    {
        return $this->token !== null && $this->token !== '';
    }

    public function send(int|string $chatId, TelegramMessage $message): bool
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $message->text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if ($message->buttons !== []) {
            $payload['reply_markup'] = ['inline_keyboard' => $message->buttons];
        }

        return $this->call('sendMessage', $payload);
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text): bool
    {
        return $this->request('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            'text' => Str::limit($text, 190),
        ]) !== null;
    }

    public function setWebhook(string $url, string $secretToken): bool
    {
        return $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secretToken,
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => false,
        ]);
    }

    /**
     * Only allowlisted, secret-free diagnostic fields may leave this client.
     *
     * @return array<string, mixed>
     */
    public function connectionStatus(string $expectedUrl, string $expectedUsername): array
    {
        $identity = $this->request('getMe', []);
        $webhook = $this->request('getWebhookInfo', []);
        $bot = $identity['result']['username'] ?? null;
        $url = (string) ($webhook['result']['url'] ?? '');
        $error = (string) ($webhook['result']['last_error_message'] ?? '');
        // Telegram delivery errors can quote the full secret-bearing webhook URL.
        $secret = basename((string) parse_url($expectedUrl, PHP_URL_PATH));
        $error = str_replace(array_filter([$this->token, (string) config('services.telegram.webhook_secret'), $expectedUrl, $secret]), '[redacted]', $error);
        $error = preg_replace('~https?://[^\s<>]+~i', '[URL hidden]', $error) ?? '';

        return [
            'api_ok' => $identity !== null && $webhook !== null,
            'bot_username' => $bot,
            'bot_matches' => is_string($bot) && strcasecmp($bot, ltrim($expectedUsername, '@')) === 0,
            'webhook_set' => $url !== '',
            'webhook_matches' => $url !== '' && hash_equals($expectedUrl, $url),
            'pending_updates' => (int) ($webhook['result']['pending_update_count'] ?? 0),
            'last_error' => mb_substr($error, 0, 300),
            'last_error_at' => $webhook['result']['last_error_date'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function call(string $method, array $payload): bool
    {
        return $this->request($method, $payload) !== null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function request(string $method, array $payload): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $started = microtime(true);
        $status = null;
        $ok = false;
        $error = null;
        $description = null;

        try {
            $client = Http::timeout(5)->connectTimeout(3)->acceptJson();
            $url = rtrim($this->apiBase, '/').'/bot'.$this->token.'/'.$method;
            $response = in_array($method, ['getMe', 'getWebhookInfo'], true)
                ? $client->get($url)
                : $client->post($url, $payload);

            $status = $response->status();
            $ok = $response->successful() && $response->json('ok') === true;
            $description = $ok ? null : (string) $response->json('description');
        } catch (Throwable $exception) {
            // Transport exceptions include the request URL, which contains the bot token.
            // Do not report the raw exception to Laravel or persist its message.
            $error = 'Telegram API request failed ('.get_class($exception).'). Check server connectivity and bot configuration.';
        }

        IntegrationLog::create([
            'integration' => 'telegram',
            'direction' => 'out',
            'action' => $method,
            'status_code' => $status,
            'ok' => $ok,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            // Never log the token or full message bodies (they can contain client data).
            'request' => ['chat_id' => $payload['chat_id'] ?? null, 'length' => isset($payload['text']) ? mb_strlen((string) $payload['text']) : null],
            'response' => $description !== null ? ['description' => $description] : null,
            'error' => $error,
            'created_at' => now(),
        ]);

        return $ok ? $response->json() : null;
    }
}
