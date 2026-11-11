<?php

declare(strict_types=1);

namespace App\Domain\Website\Support;

use App\Domain\Website\Models\ThemeToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * The site's theme as one small CSS file (V2-1, docs/12 §5.1, D-116): the database tokens printed as CSS
 * custom properties. Every v1 page already links /assets/theme.css last — the stylesheet this renders — so a
 * saved token re-skins the live site on the visitor's next load, no deploy. The v2 pages link
 * /theme/{hash}.css instead: the hash is the content, so a saved change mints a fresh immutable URL while
 * pages still holding an old URL get the current tokens with no-cache headers and re-skin anyway.
 */
final class ThemeCss
{
    public const CACHE_KEY = 'woodex:theme-css';

    /**
     * The live stylesheets' duplicate vocabulary: keys the v1 CSS defines separately but always reads as the
     * same colour. Each alias is emitted with its token's value, so one edit moves both.
     *
     * @var array<string, list<string>>
     */
    private const ALIASES = [
        '--navy' => ['--wx-navy', '--wx-accent'],
        '--ink' => ['--wx-ink'],
        '--muted' => ['--wx-muted'],
        '--charcoal' => [],
        '--paper' => ['--wx-paper'],
        '--surface' => ['--wx-surface', '--beige', '--cream', '--wx-beige'],
        '--line' => ['--wx-line'],
        '--navy-line' => ['--wx-line-dark'],
        '--light-gray' => ['--deep-gray', '--silver'],
        '--shadow' => ['--wx-shadow'],
        '--r-lg' => ['--r-md', '--r-xl', '--wx-r-sm', '--wx-r-md', '--wx-r-xl'],
        '--wx-font' => ['--wx-font-head'],
    ];

    /**
     * The v2 section templates' vocabulary (docs/05 §2), derived from the tokens above rather than stored, so
     * there is one source of truth and the two vocabularies can never drift apart. The tints use color-mix
     * (the same modern baseline Tailwind v4 needs) and re-derive automatically when a brand colour changes.
     */
    private const DERIVED = <<<'CSS'
    --color-navy-900: var(--navy);
    --color-navy-800: var(--navy-2);
    --color-navy-950: color-mix(in srgb, var(--navy) 65%, #000);
    --color-navy-300: color-mix(in srgb, var(--navy) 32%, #fff);
    --color-navy-200: color-mix(in srgb, var(--navy) 18%, #fff);
    --color-navy-100: color-mix(in srgb, var(--navy) 10%, #fff);
    --color-navy-50: color-mix(in srgb, var(--navy) 5%, #fff);
    --color-wood-500: var(--wood);
    --color-wood-600: color-mix(in srgb, var(--wood) 78%, #000);
    --color-sand-100: var(--surface);
    --color-sand-50: color-mix(in srgb, var(--surface) 50%, #fff);
    --color-ink: var(--ink);
    --color-body: var(--muted);
    --color-muted: var(--muted);
    --color-paper: var(--paper);
    --color-soft: var(--surface);
    --color-beige: var(--surface);
    --color-line: var(--line);
    --font-heading: var(--wx-font);
    --font-sans: var(--wx-font);
    --radius-ui: var(--r-lg);
    CSS;

    /**
     * Repairs for defects the v1 stylesheets carry onto the live site (found by the P9 audit
     * checks, 18 Oct 2026). theme.css loads last on every v1 page, so an equally-specific rule
     * here wins. Content-neutral, phone-only, documented — anything broader belongs in the CMS.
     */
    private const FIXES = <<<'CSS'
    @media (max-width: 560px) {
        /* site-p21.css forces the About page's stat band into three columns even inside its own
           560px breakpoint (#about-story .ab-stats), outranking the page's own phone rule
           (.ab-stats { grid-template-columns: 1fr }) — the cards overflowed a 390px phone to
           595px. Stacked cards are the v1 design's own intent for phones. */
        #about-story .ab-stats { grid-template-columns: 1fr; }
    }
    CSS;

    /**
     * The stylesheet: every token as a custom property on :root, its aliases with the same value,
     * the v2 vocabulary derived from them, and the v1 defect repairs.
     */
    public function css(): string
    {
        // Between a deploy and the one-click Database update (docs/06 §2.4) the table may not exist yet:
        // the theme then degrades to "no override" — every page falls back to its own stylesheet defaults,
        // which are the same v1 values — and nothing is cached, so the real theme appears with the update.
        if (! Schema::hasTable('theme_tokens')) {
            return ":root {}\n";
        }

        return Cache::remember(self::CACHE_KEY, now()->addDay(), function (): string {
            $lines = [];
            $tokens = ThemeToken::query()->orderBy('sort')->orderBy('id')->get();
            $present = [];

            foreach ($tokens as $token) {
                $lines[] = '  '.$token->key.': '.$token->value.';';
                $present[$token->key] = true;
            }

            // An alias fills a gap, never overwrites a real token: once the Theme Builder owns a
            // key (a separate heading font, for example), its own value must win.
            foreach ($tokens as $token) {
                foreach (self::ALIASES[$token->key] ?? [] as $alias) {
                    if (! isset($present[$alias])) {
                        $lines[] = '  '.$alias.': '.$token->value.';';
                    }
                }
            }

            return ":root {\n".implode("\n", $lines)."\n\n".self::DERIVED."\n}\n\n".self::FIXES."\n";
        });
    }

    /**
     * The current content hash — the URL v2 pages link to.
     */
    public function hash(): string
    {
        return substr(md5($this->css()), 0, 12);
    }

    /**
     * URL for the layout: /theme/{hash}.css.
     */
    public function url(): string
    {
        return url('/theme/'.$this->hash().'.css');
    }

    /**
     * Headers for a hashed request: the exact version is immutable for a year; an older URL (a cached page)
     * still gets the current tokens, but never cached, so the next save shows up on the next load.
     *
     * @return array<string, string>
     */
    public function headersFor(string $requestedHash): array
    {
        $fresh = hash_equals($this->hash(), $requestedHash);

        return [
            'Content-Type' => 'text/css; charset=utf-8',
            'Cache-Control' => $fresh
                ? 'public, max-age=31536000, immutable'
                : 'public, max-age=0, must-revalidate',
        ];
    }

    /**
     * Headers for the live site's /assets/theme.css: briefly cacheable — the link in the v1 pages' HTML is
     * static (?v=p21), so this is the only way a saved change reaches visitors.
     *
     * @return array<string, string>
     */
    public function liveHeaders(): array
    {
        return [
            'Content-Type' => 'text/css; charset=utf-8',
            'Cache-Control' => 'public, max-age=60',
        ];
    }

    /**
     * After a save: drop the cached CSS so the next request mints the new hash.
     */
    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
