<?php

declare(strict_types=1);

namespace App\Domain\Website\Support;

use Illuminate\Validation\ValidationException;

/**
 * The script guard of the Visual Page Builder (ported from the v2.1 `code_guard`, finding F-05).
 *
 * A page document is HTML the staff can change, so the one dangerous operation is adding executable
 * code: a <script>, an on…= handler, a javascript: link, an embed or a meta refresh. Editors may keep
 * every token the page already has (and every token the rest of the site already uses), but adding a
 * new one is the owner's permission — the same rule the old builder enforced, and the same intent as
 * the owner-only HTML block (A-01).
 */
final class HtmlGuard
{
    /**
     * The executable tokens of a document, normalised so a harmless reformat is not "new".
     *
     * @return list<string>
     */
    public static function tokens(string $html): array
    {
        $tokens = [];

        preg_match_all('~<script\b[^>]*>.*?</script\s*>~is', $html, $scripts);
        foreach ($scripts[0] as $script) {
            // Structured data is content, not code.
            if (preg_match('~^<script\b[^>]*type\s*=\s*["\']?application/ld\+json~i', $script) === 1) {
                continue;
            }

            $tokens[] = self::squash($script);
        }

        if (preg_match('~<script\b(?![^>]*>.*?</script)~is', $html) === 1) {
            $tokens[] = '<script-unclosed>';
        }

        preg_match_all('~<[a-z][^>]*>~i', $html, $tags);
        foreach ($tags[0] as $tag) {
            preg_match_all('~[\s/"\']on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)~i', $tag, $handlers);
            foreach ($handlers[0] as $handler) {
                $tokens[] = self::squash($handler);
            }

            preg_match_all('~[\s/"\'](?:href|src|action|formaction|xlink:href|srcdoc|poster|background|cite|ping)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)~i', $tag, $urls);
            foreach ($urls[1] as $index => $value) {
                $decoded = self::decode($value);

                if (preg_match('~^(javascript|vbscript|data:text/html|data:image/svg)~i', $decoded) === 1
                    || preg_match('~<script|on[a-z]+=~i', $decoded) === 1) {
                    $tokens[] = self::squash($urls[0][$index]);
                }
            }
        }

        preg_match_all('~<(iframe|object|embed|base|frame|frameset)\b[^>]*>|<meta\b[^>]*http-equiv[^>]*>~i', $html, $embeds);
        foreach ($embeds[0] as $embed) {
            $tokens[] = self::squash($embed);
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Throws unless every executable token in the new document is already in the old one or elsewhere
     * on the site.
     *
     * @param  list<string>  $siteHtml  the other HTML pages, so a shared script stays allowed
     */
    public static function assertNoNewCode(string $html, string $before, array $siteHtml = []): void
    {
        $new = array_diff(self::tokens($html), self::tokens($before));

        if ($new === []) {
            return;
        }

        $known = [];

        foreach ($siteHtml as $document) {
            $known = array_merge($known, self::tokens($document));
        }

        $new = array_diff($new, $known);

        if ($new === []) {
            return;
        }

        throw ValidationException::withMessages([
            'html' => 'Only the owner can add scripts, embeds or inline event code to a page. Remove: '
                .mb_substr((string) reset($new), 0, 80),
        ]);
    }

    private static function squash(string $value): string
    {
        return (string) preg_replace('~\s+~', ' ', trim($value));
    }

    private static function decode(string $value): string
    {
        $value = trim($value, '"\'');

        // Twice: one pass for the attribute, one for the entity that hides it (&#106;avascript:).
        $value = html_entity_decode(html_entity_decode($value, ENT_QUOTES | ENT_HTML5), ENT_QUOTES | ENT_HTML5);

        $value = (string) preg_replace_callback(
            '~&#(?:x0*([0-9a-f]+)|0*([0-9]+));?~i',
            static fn (array $match): string => (string) mb_chr($match[1] !== '' ? (int) hexdec($match[1]) : (int) $match[2]),
            $value,
        );

        return strtolower((string) preg_replace('~[\x00-\x20]+~', '', $value));
    }
}
