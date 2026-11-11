<?php

declare(strict_types=1);

namespace App\Domain\Website\Builder;

/**
 * A global section's copy inside a page file (docs/audit/21 §5.2).
 *
 * The builder marks a global section's root element with `data-wx-global="<id>"`. That attribute is the whole
 * link: the library holds the master HTML, and every page that carries the attribute holds a copy that can be
 * replaced. This class is the string surgery that finds such an element and swaps it — no DOM round trip,
 * because serialising a whole hand-written page through `DOMDocument` rewrites doctypes, entities and inline
 * script whitespace, and a sync must not touch anything except the one section.
 *
 * The tag scan is quote-aware (an `alt="a > b"` does not end a tag) and counts nested copies of the same tag,
 * so a `<section>` inside a `<section>` closes in the right place.
 */
final class GlobalSection
{
    public static function needle(int $id): string
    {
        return 'data-wx-global="'.$id.'"';
    }

    /** How many copies of the section a document carries. More than one is a mistake worth naming. */
    public static function count(string $html, int $id): int
    {
        return substr_count($html, self::needle($id));
    }

    public static function contains(string $html, int $id): bool
    {
        return str_contains($html, self::needle($id));
    }

    /**
     * The block's HTML as a page copy: the root element carrying `data-wx-global="<id>"`, exactly once.
     *
     * A block saved from a page can already carry the attribute; it is removed first so a section is never
     * linked to itself twice, and the attribute always ends up on the outermost tag.
     */
    public static function attribute(string $html, int $id): string
    {
        $html = trim($html);

        if (preg_match('~^<[a-z][a-z0-9-]*~i', $html) !== 1) {
            return $html;
        }

        $end = self::tagEnd($html, 0);

        if ($end === false) {
            return $html;
        }

        $head = substr($html, 0, $end);
        $rest = substr($html, $end);

        $head = (string) preg_replace('~\s+data-wx-global\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)~i', '', $head);

        return $head.' '.self::needle($id).$rest;
    }

    /**
     * Replace every copy of the section in a document.
     *
     * @return array{html: string, count: int}
     */
    public static function replace(string $html, int $id, string $replacement): array
    {
        $ranges = self::ranges($html, $id);
        $count = 0;

        // Back to front, so replacing one copy does not move the offsets of the ones still to do. The ranges
        // are found before anything is written: the replacement carries the same marker, so searching again
        // after a write would find the new copy and replace it for ever.
        foreach (array_reverse($ranges) as $range) {
            $html = substr($html, 0, $range['start']).$replacement.substr($html, $range['end']);
            $count++;
        }

        return ['html' => $html, 'count' => $count];
    }

    /**
     * Every copy's range in the document, in document order.
     *
     * @return list<array{start: int, end: int}>
     */
    public static function ranges(string $html, int $id): array
    {
        $ranges = [];
        $offset = 0;

        while ($offset < strlen($html) && ($range = self::range(substr($html, $offset), $id)) !== null) {
            $absolute = ['start' => $range['start'] + $offset, 'end' => $range['end'] + $offset];
            $ranges[] = $absolute;
            $offset = $absolute['end'];

            if (count($ranges) >= 20) {
                break;
            }
        }

        return $ranges;
    }

    /**
     * The byte range of the first element that carries the attribute: `['start' => …, 'end' => …]`, end
     * exclusive.
     *
     * @return array{start: int, end: int}|null
     */
    public static function range(string $html, int $id): ?array
    {
        $at = strpos($html, self::needle($id));

        if ($at === false) {
            return null;
        }

        // The tag that owns the attribute opens at the last `<` before it; anything else means the attribute is
        // in text or a comment, and there is no element to replace.
        $before = substr($html, 0, $at);
        $open = strrpos($before, '<');

        if ($open === false) {
            return null;
        }

        if (preg_match('~^<([a-z][a-z0-9-]*)~i', substr($html, $open, 80), $match) !== 1) {
            return null;
        }

        $tag = strtolower($match[1]);
        $openEnd = self::tagEnd($html, $open);

        if ($openEnd === false) {
            return null;
        }

        // A self-closing root holds no content: it ends with its own opening tag.
        if ($html[max(0, $openEnd - 1)] === '/') {
            return ['start' => $open, 'end' => $openEnd + 1];
        }

        $depth = 1;
        $position = $openEnd + 1;

        while (true) {
            $found = preg_match('~<(/?)'.preg_quote($tag, '~').'(?=[\s>/])~i', $html, $next, PREG_OFFSET_CAPTURE, $position);

            if ($found !== 1) {
                return null;
            }

            $start = (int) $next[0][1];
            $closing = $next[1][0] === '/';

            $end = self::tagEnd($html, $start);

            if ($end === false) {
                return null;
            }

            if (! $closing && $html[max(0, $end - 1)] === '/') {
                // `<tag … />` opens and closes in one go.
                $position = $end + 1;

                continue;
            }

            $depth += $closing ? -1 : 1;

            if ($depth === 0) {
                return ['start' => $open, 'end' => $end + 1];
            }

            $position = $end + 1;
        }
    }

    /** The offset of the `>` that closes the tag starting at `$open`, ignoring `>` inside a quoted value. */
    private static function tagEnd(string $html, int $open): int|false
    {
        $length = strlen($html);
        $quote = null;

        for ($i = $open; $i < $length; $i++) {
            $char = $html[$i];

            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }

            if ($char === '>') {
                return $i;
            }
        }

        return false;
    }
}
