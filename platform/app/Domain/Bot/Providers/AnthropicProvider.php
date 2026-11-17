<?php

declare(strict_types=1);

namespace App\Domain\Bot\Providers;

use App\Domain\Bot\BotReply;
use App\Domain\Bot\Contracts\BotProvider;
use App\Settings\BotSettings;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Claude (BOT-002's default pick). The key lives encrypted in Settings → Bot, never in .env-only. */
final class AnthropicProvider implements BotProvider
{
    private const URL = 'https://api.anthropic.com/v1/messages';

    public function __construct(private readonly BotSettings $settings) {}

    public function isConfigured(): bool
    {
        return trim((string) $this->settings->api_key) !== '';
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function complete(string $system, array $messages): BotReply
    {
        try {
            $response = Http::timeout(20)->connectTimeout(5)
                ->withHeaders([
                    'x-api-key' => (string) $this->settings->api_key,
                    'anthropic-version' => '2023-06-01',
                ])
                ->post(self::URL, [
                    'model' => trim($this->settings->model) !== '' ? trim($this->settings->model) : 'claude-sonnet-4-5',
                    'max_tokens' => 600,
                    'system' => $system,
                    'messages' => array_values(array_map(
                        static fn (array $message): array => ['role' => $message['role'], 'content' => $message['content']],
                        $messages,
                    )),
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return BotReply::failed($exception->getMessage());
        }

        if (! $response->successful()) {
            return BotReply::failed('Claude answered '.$response->status().'.');
        }

        $text = collect((array) $response->json('content'))
            ->filter(fn (mixed $part): bool => is_array($part) && ($part['type'] ?? null) === 'text')
            ->map(static fn (array $part): string => (string) ($part['text'] ?? ''))
            ->implode('');

        return $text === ''
            ? BotReply::failed('Claude returned an empty answer.')
            : new BotReply(
                $text,
                (int) $response->json('usage.input_tokens', 0),
                (int) $response->json('usage.output_tokens', 0),
            );
    }
}
