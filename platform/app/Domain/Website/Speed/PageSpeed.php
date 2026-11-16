<?php

declare(strict_types=1);

namespace App\Domain\Website\Speed;

use App\Settings\SpeedSettings;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The speed dashboard's Google half (V2-3 F9, docs/14 §5a): one PageSpeed run per call, behind the
 * studio's own API key. Keyless — no key saved — the buttons stay hidden and nothing is asked of
 * Google; the internal checks carry the screen on their own. A run that fails returns null and the
 * screen says so; a run that succeeds is stored by the caller as history.
 */
final class PageSpeed
{
    public function __construct(private readonly SpeedSettings $settings) {}

    public function isConfigured(): bool
    {
        return trim((string) $this->settings->google_api_key) !== '';
    }

    /**
     * @return array{score: int, metrics: array<string, string>}|null
     */
    public function run(string $url, string $strategy): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::timeout(90)->connectTimeout(10)->get('https://www.googleapis.com/pagespeedonline/v5/runPagespeed', [
                'url' => $url,
                'strategy' => $strategy === 'desktop' ? 'desktop' : 'mobile',
                'category' => 'performance',
                'key' => (string) $this->settings->google_api_key,
            ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $score = $response->json('lighthouseResult.categories.performance.score');

        if (! is_numeric($score)) {
            return null;
        }

        $audits = (array) $response->json('lighthouseResult.audits', []);

        $pick = static fn (string $key): string => trim((string) ($audits[$key]['displayValue'] ?? ''));

        return [
            'score' => (int) round(((float) $score) * 100),
            'metrics' => array_filter([
                'First contentful paint' => $pick('first-contentful-paint'),
                'Largest contentful paint' => $pick('largest-contentful-paint'),
                'Total page weight' => $pick('total-byte-weight'),
                'Render-blocking resources' => $pick('render-blocking-resources'),
                'Modern image formats' => $pick('modern-image-formats'),
                'Unused JavaScript' => $pick('unused-javascript'),
            ], static fn (string $value): bool => $value !== ''),
        ];
    }
}
