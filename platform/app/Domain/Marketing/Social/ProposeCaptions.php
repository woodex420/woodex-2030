<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Social;

use App\Settings\BotSettings;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The social planner's AI assist (P7, SOC-009): caption drafts in English and Urdu from whatever
 * the studio has already written. Keyless (provider "none" or empty key) the button stays hidden;
 * with the studio's model key it proposes, and the studio edits — nothing is posted by a model.
 */
final class ProposeCaptions
{
    public function __construct(private readonly BotSettings $settings) {}

    public function isConfigured(): bool
    {
        return $this->settings->provider !== 'none' && trim((string) $this->settings->api_key) !== '';
    }

    /**
     * @return array{text: string, text_ur: string}|null
     */
    public function propose(string $notes): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $system = <<<'TXT'
            You draft social media captions for an interior design studio in Lahore.
            Answer with JSON only, no markdown: {"text": string, "text_ur": string}.
            Rules: "text" is English, warm and concrete, at most 300 characters, no hashtag spam;
            "text_ur" is natural Urdu (Nastaliq-friendly script), the same message, not a literal translation.
            If the studio's notes are empty, write about thoughtful interiors and natural materials.
            TXT;

        $text = match ($this->settings->provider) {
            'openai' => $this->openai($system, $notes),
            'gemini' => $this->gemini($system, $notes),
            default => $this->anthropic($system, $notes),
        };

        if ($text === null) {
            return null;
        }

        $json = json_decode(trim($text, " \t\n\r\0\x0B\"`"), true);

        if (! is_array($json) || ! isset($json['text'], $json['text_ur'])) {
            return null;
        }

        return ['text' => mb_substr(trim((string) $json['text']), 0, 2200), 'text_ur' => mb_substr(trim((string) $json['text_ur']), 0, 2200)];
    }

    private function openai(string $system, string $notes): ?string
    {
        try {
            $response = Http::timeout(20)->connectTimeout(5)
                ->withToken((string) $this->settings->api_key)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => trim((string) $this->settings->model) !== '' ? trim((string) $this->settings->model) : 'gpt-4o-mini',
                    'max_tokens' => 400,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $notes !== '' ? "Studio notes: {$notes}" : 'Draft a caption.'],
                    ],
                ]);
        } catch (Throwable) {
            return null;
        }

        return $response->successful() ? (string) $response->json('choices.0.message.content', '') : null;
    }

    private function anthropic(string $system, string $notes): ?string
    {
        try {
            $response = Http::timeout(20)->connectTimeout(5)
                ->withHeaders(['x-api-key' => (string) $this->settings->api_key])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => trim((string) $this->settings->model) !== '' ? trim((string) $this->settings->model) : 'claude-3-5-haiku-latest',
                    'max_tokens' => 400,
                    'system' => $system,
                    'messages' => [['role' => 'user', 'content' => $notes !== '' ? "Studio notes: {$notes}" : 'Draft a caption.']],
                ]);
        } catch (Throwable) {
            return null;
        }

        return $response->successful() ? (string) $response->json('content.0.text', '') : null;
    }

    private function gemini(string $system, string $notes): ?string
    {
        try {
            $response = Http::timeout(20)->connectTimeout(5)
                ->withQueryParameters(['key' => (string) $this->settings->api_key])
                ->post('https://generativelanguage.googleapis.com/v1beta/models/'.(trim((string) $this->settings->model) !== '' ? trim((string) $this->settings->model) : 'gemini-1.5-flash').':generateContent', [
                    'systemInstruction' => ['parts' => [['text' => $system]]],
                    'contents' => [['parts' => [['text' => $notes !== '' ? "Studio notes: {$notes}" : 'Draft a caption.']]]],
                ]);
        } catch (Throwable) {
            return null;
        }

        return $response->successful() ? (string) $response->json('candidates.0.content.parts.0.text', '') : null;
    }
}
