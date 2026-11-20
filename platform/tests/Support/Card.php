<?php

namespace Tests\Support;

use Dom\Element;
use Dom\Node;

/**
 * A row of the shared table as it stands on a card (master list T-042), read as a screen reader reads it: what
 * names the row, then each fact with its word before it, and where the row leads.
 *
 * On a narrow screen the stylesheet lays a row out as a card: the cell that names the row, then every cell the
 * list gave a data-label. A cell without one is left off the card. Nothing of that is a second drawing, so a test
 * can read the card from the row itself. How it looks is measured by a browser (scripts/os-journeys.mjs).
 */
final class Card
{
    /** @return array{name: string, facts: list<string>, leads: ?string} */
    public static function of(Element $row): array
    {
        $facts = [];
        $name = null;

        foreach ($row->children as $cell) {
            if ($cell->tagName === 'TH' && $cell->getAttribute('scope') === 'row') {
                $name ??= $cell;
            }
            if ($cell->tagName === 'TD' && $cell->hasAttribute('data-label')) {
                $facts[] = $cell->getAttribute('data-label').': '.self::said($cell);
            }
        }

        return [
            'name' => self::said($name),
            'facts' => $facts,
            'leads' => $name?->querySelector('a[href]')?->getAttribute('href'),
        ];
    }

    /**
     * The words of the facts on a card, in their order: ['Contact', 'Stage', …].
     *
     * @return list<string>
     */
    public static function words(Element $row): array
    {
        $words = [];

        foreach ($row->children as $cell) {
            if ($cell->tagName === 'TD' && $cell->hasAttribute('data-label')) {
                $words[] = $cell->getAttribute('data-label');
            }
        }

        return $words;
    }

    /**
     * The cells whose word on the card is not the heading of their column, word for word. None, when the two
     * agree: what a card calls a fact is what the table calls its column.
     *
     * @return list<string>
     */
    public static function wordsThatDiffer(Element $row): array
    {
        $headings = [...$row->closest('table')->querySelectorAll('thead th')];
        $differ = [];

        foreach ([...$row->children] as $index => $cell) {
            if (! $cell->hasAttribute('data-label')) {
                continue;
            }

            $heading = $headings[$index] ?? null;
            // A heading that sorts is a button: its first span is the words, the rest says how it is sorted.
            $over = self::said($heading?->querySelector('button > span:first-child') ?? $heading);

            if ($cell->getAttribute('data-label') !== $over) {
                $differ[] = $cell->getAttribute('data-label').' under "'.$over.'"';
            }
        }

        return $differ;
    }

    private static function said(?Node $node): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $node?->textContent ?? ''));
    }
}
