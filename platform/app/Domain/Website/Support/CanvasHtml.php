<?php

declare(strict_types=1);

namespace App\Domain\Website\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * The one guardrail for staff-authored HTML (the HTML block and the campaign canvas).
 *
 * The studio writes free markup for a landing page; this cleaner makes sure the freedom stops at
 * code that could run. It parses the fragment with DOMDocument and rebuilds it from an allow-list:
 * tags, attributes and URL schemes are checked after the browser has decoded entities, so the
 * unquoted attributes, backticks and `&#115;` tricks that beat the old regex never survive.
 *
 * Layout, inline styles and embeds stay — scripts, event handlers and `javascript:` URLs do not.
 * Cleaned when saved and again when rendered; both calls are cheap.
 */
final class CanvasHtml
{
    /**
     * tag => allowed attributes. Anything not listed is removed; unknown tags are unwrapped (their
     * text survives) except the drop-with-content list below.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        'section' => ['class', 'style', 'id'],
        'div' => ['class', 'style', 'id'],
        'article' => ['class', 'style', 'id'],
        'header' => ['class', 'style', 'id'],
        'footer' => ['class', 'style', 'id'],
        'main' => ['class', 'style', 'id'],
        'aside' => ['class', 'style', 'id'],
        'nav' => ['class', 'style', 'id'],
        'span' => ['class', 'style', 'id'],
        'p' => ['class', 'style', 'id'],
        'h1' => ['class', 'style', 'id'],
        'h2' => ['class', 'style', 'id'],
        'h3' => ['class', 'style', 'id'],
        'h4' => ['class', 'style', 'id'],
        'h5' => ['class', 'style', 'id'],
        'h6' => ['class', 'style', 'id'],
        'a' => ['href', 'target', 'rel', 'title', 'class', 'style', 'id'],
        'ul' => ['class', 'style', 'id'],
        'ol' => ['class', 'style', 'id'],
        'li' => ['class', 'style', 'id'],
        'dl' => ['class', 'style', 'id'],
        'dt' => ['class', 'style', 'id'],
        'dd' => ['class', 'style', 'id'],
        'strong' => ['class', 'style'],
        'b' => ['class', 'style'],
        'em' => ['class', 'style'],
        'i' => ['class', 'style'],
        'u' => ['class', 'style'],
        'small' => ['class', 'style'],
        'mark' => ['class', 'style'],
        'br' => [],
        'hr' => ['class', 'style'],
        'blockquote' => ['class', 'style', 'cite'],
        'table' => ['class', 'style'],
        'thead' => ['class', 'style'],
        'tbody' => ['class', 'style'],
        'tfoot' => ['class', 'style'],
        'tr' => ['class', 'style'],
        'th' => ['class', 'style', 'colspan', 'rowspan', 'scope'],
        'td' => ['class', 'style', 'colspan', 'rowspan'],
        'img' => ['src', 'srcset', 'sizes', 'alt', 'width', 'height', 'loading', 'class', 'style', 'title'],
        'figure' => ['class', 'style'],
        'figcaption' => ['class', 'style'],
        'picture' => ['class', 'style'],
        'source' => ['srcset', 'sizes', 'media', 'type'],
        'iframe' => ['src', 'width', 'height', 'title', 'loading', 'class', 'style', 'allow', 'allowfullscreen', 'frameborder', 'referrerpolicy'],
        'video' => ['src', 'poster', 'width', 'height', 'controls', 'muted', 'loop', 'playsinline', 'class', 'style'],
        'audio' => ['src', 'controls', 'loop', 'class', 'style'],
        'style' => [],
    ];

    /** Removed together with their contents: text inside these is never page copy. */
    private const DROP = [
        'script', 'noscript', 'template', 'object', 'embed', 'applet', 'base', 'meta', 'link',
        'form', 'input', 'button', 'select', 'option', 'textarea', 'svg', 'math', 'canvas',
    ];

    /** URL-bearing attributes, checked after entity decoding. */
    private const URL_ATTRIBUTES = ['href', 'src', 'poster', 'action'];

    private const SAFE_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public static function clean(string $html): string
    {
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');

        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div data-wx-root>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = null;

        foreach ($document->getElementsByTagName('div') as $candidate) {
            if ($candidate instanceof DOMElement && $candidate->hasAttribute('data-wx-root')) {
                $root = $candidate;
                break;
            }
        }

        if (! $root instanceof DOMElement) {
            return '';
        }

        // The XML encoding hint is a processing instruction, not content.
        foreach (iterator_to_array($root->childNodes) as $child) {
            if ($child->nodeType === XML_PI_NODE) {
                $root->removeChild($child);
            }
        }

        self::walk($root);

        $clean = '';

        foreach ($root->childNodes as $child) {
            $clean .= (string) $document->saveHTML($child);
        }

        return trim($clean);
    }

    private static function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                self::element($child);
            }
        }
    }

    private static function element(DOMElement $element): void
    {
        $tag = strtolower($element->tagName);

        if (in_array($tag, self::DROP, true)) {
            $element->parentNode?->removeChild($element);

            return;
        }

        if (! isset(self::ALLOWED[$tag])) {
            // Unknown (but harmless) wrapper: keep the contents, lose the tag and its attributes.
            $parent = $element->parentNode;

            if ($parent === null) {
                return;
            }

            while ($element->firstChild !== null) {
                $parent->insertBefore($element->firstChild, $element);
            }

            $parent->removeChild($element);

            return;
        }

        $allowed = self::ALLOWED[$tag];

        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = (string) $attribute->nodeValue;

            if (! in_array($name, $allowed, true)) {
                $element->removeAttribute($attribute->nodeName);

                continue;
            }

            if (in_array($name, self::URL_ATTRIBUTES, true)) {
                $safe = self::url($value);

                if ($safe === null) {
                    $element->removeAttribute($attribute->nodeName);
                } else {
                    $element->setAttribute($attribute->nodeName, $safe);
                }

                continue;
            }

            if ($name === 'srcset') {
                $safe = self::srcset($value);

                if ($safe === '') {
                    $element->removeAttribute('srcset');
                } else {
                    $element->setAttribute('srcset', $safe);
                }

                continue;
            }

            if ($name === 'style') {
                $element->setAttribute('style', self::css($value));

                continue;
            }

            if ($name === 'target' && strtolower($value) === '_blank') {
                $rel = trim($element->getAttribute('rel').' noopener noreferrer');
                $element->setAttribute('rel', trim($rel));
            }
        }

        if ($tag === 'style') {
            $element->textContent = self::css($element->textContent);

            return;
        }

        self::walk($element);
    }

    /** A single URL: decoded, stripped of the characters browsers ignore, and scheme-checked. */
    private static function url(string $value): ?string
    {
        $url = self::decode($value);

        if ($url === '') {
            return '';
        }

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $url, $match) === 1) {
            return in_array(strtolower($match[1]), self::SAFE_SCHEMES, true) ? $url : null;
        }

        // Relative paths, fragments and protocol-relative URLs are fine.
        return $url;
    }

    private static function srcset(string $value): string
    {
        $candidates = [];

        foreach (explode(',', self::decode($value)) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $candidate, 2) ?: [];
            $url = self::url((string) ($parts[0] ?? ''));

            if ($url === null || $url === '') {
                continue;
            }

            $candidates[] = trim($url.' '.($parts[1] ?? ''));
        }

        return implode(', ', $candidates);
    }

    /** Inline CSS and <style> blocks: no imports, expressions, behaviours or javascript URLs. */
    private static function css(string $css): string
    {
        $css = (string) preg_replace('/@import[^;]*;?/i', '', $css);
        $css = (string) preg_replace('/expression\s*\([^)]*\)/i', '', $css);
        $css = (string) preg_replace('/behaviou?r\s*:[^;]*;?/i', '', $css);

        return str_ireplace('javascript:', '', $css);
    }

    /**
     * Decode entities the way the browser will, then remove the whitespace and control characters
     * that are used to disguise schemes (`java&#115;cript:`, `java\tscript:`).
     */
    private static function decode(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = (string) preg_replace('/[\x00-\x20\x7F]+/', '', $value);

        return trim(str_replace(['`', '"', "'"], '', $value));
    }
}
