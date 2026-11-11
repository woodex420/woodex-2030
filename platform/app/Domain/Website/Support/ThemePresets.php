<?php

declare(strict_types=1);

namespace App\Domain\Website\Support;

/**
 * The Theme Builder's presets (V2-3 F11, docs/14 §5a): three complete looks beside the studio's own
 * v1 palette, each defined only by the token keys it changes — every other token keeps the v1
 * value. Presets carry no code and no files: they are the seed palette with a different soul, so
 * applying one is the same as editing every token by hand, and "Back to v1 palette" always undoes
 * it.
 */
final class ThemePresets
{
    /**
     * @return list<array{name: string, description: string, swatches: list<string>, tokens: array<string, string>}>
     */
    public static function presets(): array
    {
        return [
            [
                'name' => 'Gallery serif',
                'description' => 'A quiet gallery: warm paper, deep ink, serif headings, frames almost square.',
                'swatches' => ['#FAF7F2', '#1A1713', '#A67B5B', '#2A2A2A'],
                'tokens' => [
                    '--navy' => '#1A1713',
                    '--navy-2' => '#2A251F',
                    '--wood' => '#A67B5B',
                    '--wood-2' => '#C09B7C',
                    '--ink' => '#1A1713',
                    '--muted' => '#75705F',
                    '--charcoal' => '#4A453C',
                    '--paper' => '#FAF7F2',
                    '--surface' => '#F1ECE3',
                    '--line' => 'rgba(26,23,19,.15)',
                    '--navy-line' => 'rgba(255,255,255,.24)',
                    '--light-gray' => '#E4E0D8',
                    '--shadow' => '0 18px 50px rgba(26,23,19,.12)',
                    '--wx-font' => 'Georgia, "Times New Roman", "Playfair Display", serif',
                    '--r-lg' => '2px',
                ],
            ],
            [
                'name' => 'Modern slate',
                'description' => 'Cool and architectural: slate blues, a steel accent, softer corners.',
                'swatches' => ['#0F172A', '#F1F5F9', '#7C8DA6', '#334155'],
                'tokens' => [
                    '--navy' => '#0F172A',
                    '--navy-2' => '#1E293B',
                    '--wood' => '#7C8DA6',
                    '--wood-2' => '#A3B2C7',
                    '--ink' => '#0F172A',
                    '--muted' => '#64748B',
                    '--charcoal' => '#475569',
                    '--paper' => '#FFFFFF',
                    '--surface' => '#F1F5F9',
                    '--line' => 'rgba(15,23,42,.12)',
                    '--navy-line' => 'rgba(255,255,255,.22)',
                    '--light-gray' => '#E2E8F0',
                    '--shadow' => '0 18px 50px rgba(15,23,42,.12)',
                    '--wx-font' => '"Plus Jakarta Sans", system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif',
                    '--r-lg' => '20px',
                ],
            ],
            [
                'name' => 'Warm walnut',
                'description' => "The studio's material itself: walnut browns, brass, room-warm surfaces.",
                'swatches' => ['#3E2B1F', '#F6EEE3', '#C08552', '#4E382A'],
                'tokens' => [
                    '--navy' => '#3E2B1F',
                    '--navy-2' => '#4E382A',
                    '--wood' => '#C08552',
                    '--wood-2' => '#D4A96F',
                    '--ink' => '#2E2018',
                    '--muted' => '#7A6A5B',
                    '--charcoal' => '#5C4A3C',
                    '--paper' => '#FFFDF9',
                    '--surface' => '#F6EEE3',
                    '--line' => 'rgba(62,43,31,.16)',
                    '--navy-line' => 'rgba(255,255,255,.24)',
                    '--light-gray' => '#E7DCCD',
                    '--shadow' => '0 18px 50px rgba(62,43,31,.14)',
                    '--wx-font' => '"Plus Jakarta Sans", system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif',
                    '--r-lg' => '12px',
                ],
            ],
        ];
    }

    /**
     * One preset by name, or null when the name is not on the shelf.
     *
     * @return array{name: string, description: string, swatches: list<string>, tokens: array<string, string>}|null
     */
    public static function find(string $name): ?array
    {
        foreach (self::presets() as $preset) {
            if ($preset['name'] === $name) {
                return $preset;
            }
        }

        return null;
    }
}
