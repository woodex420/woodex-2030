<?php

declare(strict_types=1);

namespace App\Domain\Bot\Providers;

use App\Domain\Bot\BotReply;
use App\Domain\Bot\Contracts\BotProvider;
use App\Settings\BotSettings;
use Illuminate\Support\Facades\Http;
use Throwable;

/** OpenAI's chat completions (BOT-002: Claude default, OpenAI/Gemini as the studio's other picks). */
final class OpenAIProvider implements BotProvider
{
    private const URL = 'https://api.openai.com/v1/chat/completions';

    public function __construct(private readonly BotSettings $settings) {}

    public function isConfigured(): bool
    {
        return trim((string) $this->settings->api_key) !== '';
    }

    public function name(): string
    {
        return 'openai';
    }

    public function complete(string $system, array $messages): BotReply
    {
        $payload = [
            'model' => trim($this->settings->model) !== '' ? trim($this->settings->model) : 'gpt-4o-mini',
            'max_tokens' => 600,
            'messages' => [['role' => 'system', 'content' => $system], ...array_values(array_map(
                static fn (array $message): array => ['role' => $message['role'], 'content' => $message['content']],
                $messages,
            ))],
        ];

        try {
            $response = Http::timeout(20)->connectTimeout(5)
                ->withToken((string) $this->settings->api_key)
                ->post(self::URL, $payload);
        } catch (Throwable $exception) {
            report($exception);

            return BotReply::failed($exception->getMessage());
        }

        if (! $response->successful()) {
            return BotReply::failed('OpenAI answered '.$response->status().'.');
        }

        $text = (string) $response->json('choices.0.message.content', '');

        return $text === ''
            ? BotReply::failed('OpenAI returned an empty answer.')
            : new BotReply(
                $text,
                (int) $response->json('usage.prompt_tokens', 0),
                (int) $response->json('usage.completion_tokens', 0),
            );
    }
}
