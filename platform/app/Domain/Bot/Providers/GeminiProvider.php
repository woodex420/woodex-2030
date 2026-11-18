<?php

declare(strict_types=1);

namespace App\Domain\Bot\Providers;

use App\Domain\Bot\BotReply;
use App\Domain\Bot\Contracts\BotProvider;
use App\Settings\BotSettings;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Google Gemini (BOT-002: the third pick). Same contract, same guardrails on the way out. */
final class GeminiProvider implements BotProvider
{
    public function __construct(private readonly BotSettings $settings) {}

    public function isConfigured(): bool
    {
        return trim((string) $this->settings->api_key) !== '';
    }

    public function name(): string
    {
        return 'gemini';
    }

    public function complete(string $system, array $messages): BotReply
    {
        $model = trim($this->settings->model) !== '' ? trim($this->settings->model) : 'gemini-2.0-flash';

        try {
            $response = Http::timeout(20)->connectTimeout(5)
                ->post('https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent', [
                    'system_instruction' => ['parts' => [['text' => $system]]],
                    'contents' => array_values(array_map(
                        static fn (array $message): array => [
                            'role' => $message['role'] === 'assistant' ? 'model' : 'user',
                            'parts' => [['text' => $message['content']]],
                        ],
                        $messages,
                    )),
                    'generationConfig' => ['maxOutputTokens' => 600],
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return BotReply::failed($exception->getMessage());
        }

        if (! $response->successful()) {
            return BotReply::failed('Gemini answered '.$response->status().'.');
        }

        $text = collect((array) $response->json('candidates.0.content.parts'))
            ->map(static fn (mixed $part): string => is_array($part) ? (string) ($part['text'] ?? '') : '')
            ->implode('');

        return $text === ''
            ? BotReply::failed('Gemini returned an empty answer.')
            : new BotReply($text, (int) $response->json('usageMetadata.promptTokenCount', 0), (int) $response->json('usageMetadata.candidatesTokenCount', 0));
    }
}
