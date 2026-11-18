<?php

declare(strict_types=1);

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Small, safe rich text for documents (scope of work, QUO-015).
 *
 * Staff type plain text: blank lines separate paragraphs, lines starting with "-", "*" or "•" become a bullet
 * list and "1." / "1)" a numbered list. fromPlainText() turns that into minimal HTML; toPlainText() turns it
 * back for editing. sanitize() keeps only an allow-list of tags (for HTML from imports), so stored HTML is always
 * safe to print in the admin, the client page and the PDF.
 */
final class Html
{
    /** @var array<string, list<string>> tag => allowed attributes */
    private const ALLOWED = [
        'p' => [], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [],
        'ul' => [], 'ol' => [], 'li' => [], 'h3' => [], 'h4' => [], 'a' => ['href'],
    ];

    public static function fromPlainText(?string $text): ?string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", (string) $text));

        if ($text === '') {
            return null;
        }

        $html = [];

        foreach (preg_split('/\n\s*\n/', $text) ?: [] as $block) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), static fn (string $line): bool => $line !== ''));

            if ($lines === []) {
                continue;
            }

            $bullets = array_filter($lines, static fn (string $line): bool => (bool) preg_match('/^[-*•]\s+/u', $line));
            $numbers = array_filter($lines, static fn (string $line): bool => (bool) preg_match('/^\d{1,2}[.)]\s+/', $line));

            if (count($bullets) === count($lines)) {
                $html[] = '<ul>'.implode('', array_map(static fn (string $line): string => '<li>'.e((string) preg_replace('/^[-*•]\s+/u', '', $line)).'</li>', $lines)).'</ul>';
            } elseif (count($numbers) === count($lines)) {
                $html[] = '<ol>'.implode('', array_map(static fn (string $line): string => '<li>'.e((string) preg_replace('/^\d{1,2}[.)]\s+/', '', $line)).'</li>', $lines)).'</ol>';
            } else {
                $html[] = '<p>'.implode('<br>', array_map(static fn (string $line): string => e($line), $lines)).'</p>';
            }
        }

        return implode("\n", $html);
    }

    public static function toPlainText(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $document = self::load(self::sanitize($html) ?? '');
        $blocks = [];

        foreach (self::root($document)->childNodes as $node) {
            $text = match (true) {
                $node instanceof DOMElement && $node->nodeName === 'ul' => self::listText($node, false),
                $node instanceof DOMElement && $node->nodeName === 'ol' => self::listText($node, true),
                default => self::inlineText($node),
            };

            if (trim($text) !== '') {
                $blocks[] = trim($text);
            }
        }

        return implode("\n\n", $blocks);
    }

    public static function sanitize(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $document = self::load($html);
        $root = self::root($document);
        self::clean($root);

        $out = '';

        foreach ($root->childNodes as $child) {
            $out .= $document->saveHTML($child);
        }

        $out = trim($out);

        return $out === '' ? null : $out;
    }

    private static function load(string $html): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div id="wx-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    private static function root(DOMDocument $document): DOMNode
    {
        return $document->getElementById('wx-root') ?? $document;
    }

    private static function clean(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMText) {
                continue;
            }

            if (! $child instanceof DOMElement) {
                $node->removeChild($child); // comments, processing instructions

                continue;
            }

            $tag = strtolower($child->nodeName);

            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'template', 'svg', 'math'], true)) {
                $node->removeChild($child);

                continue;
            }

            self::clean($child);

            if (! array_key_exists($tag, self::ALLOWED)) {
                // Unknown wrapper (div, span, font…): keep its content, drop the tag.
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);

                continue;
            }

            foreach (iterator_to_array($child->attributes ?? []) as $attribute) {
                $name = strtolower($attribute->nodeName);
                $allowed = in_array($name, self::ALLOWED[$tag], true);
                $safeValue = $name !== 'href' || preg_match('#^(https?://|mailto:|tel:)#i', trim($attribute->nodeValue ?? '')) === 1;

                if (! $allowed || ! $safeValue) {
                    $child->removeAttribute($attribute->nodeName);
                }
            }

            if ($tag === 'a' && $child->hasAttribute('href')) {
                $child->setAttribute('rel', 'noopener nofollow');
            }
        }
    }

    private static function listText(DOMElement $list, bool $numbered): string
    {
        $lines = [];
        $index = 1;

        foreach ($list->childNodes as $item) {
            if ($item instanceof DOMElement && $item->nodeName === 'li') {
                $lines[] = ($numbered ? ($index++).'. ' : '- ').trim(self::inlineText($item));
            }
        }

        return implode("\n", $lines);
    }

    private static function inlineText(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return (string) preg_replace('/\s+/u', ' ', $node->nodeValue ?? '');
        }

        if ($node instanceof DOMElement && $node->nodeName === 'br') {
            return "\n";
        }

        $text = '';

        foreach ($node->childNodes as $child) {
            $text .= self::inlineText($child);
        }

        return $text;
    }
}
