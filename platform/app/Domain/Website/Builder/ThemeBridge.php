<?php

declare(strict_types=1);

namespace App\Domain\Website\Builder;

use App\Domain\Website\Models\ThemeToken;
use App\Domain\Website\Support\ThemeCss;
use Database\Seeders\ThemeTokensSeeder;
use Illuminate\Validation\ValidationException;

/**
 * The builder's Theme tab and the Woodex OS theme, translated.
 *
 * The v2.1 panel speaks `--wx-*` keys; the OS stores `--navy`, `--r-lg` and friends. This is the
 * only place that maps one to the other — so there is one theme (theme_tokens → ThemeCss), and the
 * builder is just another screen that edits it.
 */
final class ThemeBridge
{
    /** builder key => stored token */
    private const array MAP = [
        '--wx-navy' => '--navy',
        '--wx-ink' => '--ink',
        '--wx-muted' => '--muted',
        '--wx-paper' => '--paper',
        '--wx-surface' => '--surface',
        '--wx-beige' => '--wx-beige',
        '--wx-c1' => '--wx-c1',
        '--wx-c2' => '--wx-c2',
        '--wx-c3' => '--wx-c3',
        '--wx-c4' => '--wx-c4',
        '--wx-font' => '--wx-font',
        '--wx-font-head' => '--wx-font-head',
        '--wx-base-size' => '--wx-base-size',
        '--wx-h1' => '--wx-h1',
        '--wx-h2' => '--wx-h2',
        '--wx-h3' => '--wx-h3',
        '--wx-section' => '--wx-section',
        '--wx-container' => '--wx-container',
        '--wx-btn-radius' => '--wx-btn-radius',
        '--wx-r-lg' => '--r-lg',
    ];

    /** @var array<string, string> */
    private const array DEFAULTS = [
        '--wx-navy' => '#0C1628',
        '--wx-ink' => '#000000',
        '--wx-muted' => '#525252',
        '--wx-paper' => '#FFFFFF',
        '--wx-surface' => '#F4EFE7',
        '--wx-beige' => '#FCF2E8',
        '--wx-c1' => '#D4AF6A',
        '--wx-c2' => '#1F3A5F',
        '--wx-c3' => '#6B7A5A',
        '--wx-c4' => '#B85C38',
        '--wx-font' => '"Plus Jakarta Sans", system-ui, sans-serif',
        '--wx-font-head' => '"Plus Jakarta Sans", system-ui, sans-serif',
        '--wx-base-size' => '16px',
        '--wx-h1' => '72px',
        '--wx-h2' => '48px',
        '--wx-h3' => '22px',
        '--wx-section' => '112px',
        '--wx-container' => '1240px',
        '--wx-btn-radius' => '999px',
        '--wx-r-lg' => '16px',
    ];

    /** Colour tokens, by builder key. */
    private const array COLORS = [
        '--wx-navy', '--wx-ink', '--wx-muted', '--wx-paper', '--wx-surface', '--wx-beige',
        '--wx-c1', '--wx-c2', '--wx-c3', '--wx-c4',
    ];

    /** Font keys the v2.1 panel ships with, and the stacks they mean. */
    private const array FONT_KEYS = [
        'dm' => '"DM Sans", system-ui, sans-serif',
        'system' => 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif',
        'serif' => 'Georgia, "Times New Roman", serif',
    ];

    /** Families that need a serif fallback rather than a sans one. */
    private const array SERIF_FAMILIES = [
        'Georgia', 'Times New Roman', 'Playfair Display', 'Merriweather', 'Lora',
        'DM Serif Display', 'Libre Baskerville', 'Cormorant Garamond',
    ];

    /** Families that are CSS keywords, not names — never quote them. */
    private const array GENERIC_FAMILIES = [
        'serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui',
        'ui-serif', 'ui-sans-serif', 'ui-monospace', 'ui-rounded', 'math', 'emoji',
    ];

    private const string SANS_FALLBACK = 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif';

    /** Length tokens, by builder key. */
    private const array LENGTHS = [
        '--wx-base-size', '--wx-h1', '--wx-h2', '--wx-h3',
        '--wx-section', '--wx-container', '--wx-btn-radius', '--wx-r-lg',
    ];

    /**
     * @return array<string, string>
     */
    public function vars(): array
    {
        $stored = ThemeToken::query()->pluck('value', 'key')->all();

        $vars = [];

        foreach (self::MAP as $builderKey => $tokenKey) {
            $value = (string) ($stored[$tokenKey] ?? self::DEFAULTS[$builderKey]);
            $vars[$builderKey] = $this->fontKey($builderKey) ? $this->fontCode($value) : $value;
        }

        return $vars;
    }

    /**
     * @param  array<string, mixed>  $vars
     */
    public function save(array $vars): void
    {
        // A token added after this install was seeded must exist before it can be written.
        foreach (ThemeTokensSeeder::tokens() as $index => $token) {
            ThemeToken::query()->firstOrCreate(
                ['key' => $token['key']],
                $token + ['sort' => 500 + $index],
            );
        }

        foreach (self::MAP as $builderKey => $tokenKey) {
            if (! array_key_exists($builderKey, $vars)) {
                continue;
            }

            ThemeToken::query()->where('key', $tokenKey)->update([
                'value' => $this->clean($builderKey, $vars[$builderKey]),
            ]);
        }

        app(ThemeCss::class)->flush();
    }

    private function clean(string $key, mixed $value): string
    {
        $value = trim((string) $value);

        if ($value === '' || mb_strlen($value) > 200) {
            throw ValidationException::withMessages(['theme' => 'That value is empty or too long for '.$key.'.']);
        }

        if ($this->fontKey($key)) {
            // The panel speaks v2.1: dm | system | serif | g:Family:400;600 — plus a pasted CSS stack.
            if (str_starts_with($value, 'g:')) {
                return $this->googleStack($value, $key);
            }

            if (preg_match('/[<>{};]/', $value) === 1) {
                throw ValidationException::withMessages(['theme' => $key.' contains characters a font name cannot have.']);
            }

            return $this->stack($value);
        }

        if (in_array($key, self::COLORS, true)) {
            if (preg_match('/^#[0-9a-f]{3,8}$/i', $value) !== 1
                && preg_match('/^rgba?\([0-9.,\s%]+\)$/i', $value) !== 1) {
                throw ValidationException::withMessages(['theme' => $key.' needs a hex or rgb() colour.']);
            }

            return str_starts_with($value, '#') ? strtoupper($value) : $value;
        }

        if (in_array($key, self::LENGTHS, true)) {
            if (preg_match('/^\d{1,4}(\.\d+)?(px|rem|em|%)$/', $value) !== 1) {
                throw ValidationException::withMessages(['theme' => $key.' needs a size such as 112px.']);
            }

            return $value;
        }

        return $value;
    }

    private function fontKey(string $key): bool
    {
        return $key === '--wx-font' || $key === '--wx-font-head';
    }

    /**
     * What the v2.1 panel understands: dm | system | serif | g:Family:weights.
     * The store keeps a CSS stack, so a stack is shortened back to that code here.
     */
    private function fontCode(string $stored): string
    {
        $value = trim($stored);

        if ($value === '') {
            return 'dm';
        }

        if (isset(self::FONT_KEYS[$value]) || str_starts_with($value, 'g:')) {
            return $value;
        }

        $family = trim(trim(explode(',', $value)[0]), "\"'");

        return match (true) {
            (bool) preg_match('/^dm sans$/i', $family) => 'dm',
            (bool) preg_match('/^(georgia|times new roman|serif)$/i', $family) => 'serif',
            (bool) preg_match('/^(system-ui|system)$/i', $family) => 'system',
            default => 'g:'.$family.':',
        };
    }

    /** `g:Poppins:400;600` → "Poppins", system-ui, … */
    private function googleStack(string $value, string $key): string
    {
        if (preg_match('/^g:([A-Za-z0-9 .&\'-]{1,60}):?[\d;]*$/', $value, $m) !== 1) {
            throw ValidationException::withMessages(['theme' => $key.' is not a font name.']);
        }

        $family = trim($m[1]);

        return '"'.$family.'", '.$this->fallbackFor($family);
    }

    private function fallbackFor(string $family): string
    {
        return in_array($family, self::SERIF_FAMILIES, true) ? 'Georgia, serif' : self::SANS_FALLBACK;
    }

    /** Turn whatever the panel sent into a valid, complete CSS font stack. */
    private function stack(string $value): string
    {
        if (isset(self::FONT_KEYS[$value])) {
            return self::FONT_KEYS[$value];
        }

        // A pasted stack is already a stack.
        if (str_contains($value, ',')) {
            return $value;
        }

        if (in_array($value, self::GENERIC_FAMILIES, true)) {
            return $value;
        }

        return '"'.$value.'", '.$this->fallbackFor($value);
    }
}
