<?php

declare(strict_types=1);

namespace App\Domain\Website\Services;

use App\Domain\Website\Enums\BlockType;
use Illuminate\Support\Str;

/**
 * The editor's own check (19 Oct, builder parity with admin v2.1 pro's SEO and accessibility audit):
 * the page as it stands *now*, before anyone presses Publish. PageValidator is the gate that refuses a
 * publish; this is the to-do list that stops the publish from being refused — and it also covers the
 * things the gate deliberately lets through (an image without alt text in an HTML block, two H1s, a
 * keyword that never appears).
 *
 * It reads the editor's working copy, so it updates as the page is typed, and it never writes.
 */
final class PageAudit
{
    public const string BAD = 'bad';

    public const string WARN = 'warn';

    /** A suggestion, not an issue: it never counts against the page in the Pages list. */
    public const string INFO = 'info';

    /**
     * @param  list<array<string, mixed>>  $blocks  the editor's blocks (id, key, type, visible, data, settings)
     * @param  array<int, string>  $imageAlt  media id => its alt text (empty string when it has none)
     * @return list<array{level: string, area: string, message: string}> level is bad, warn or info
     */
    public static function findings(
        string $title,
        string $seoTitle,
        string $seoDescription,
        string $focusKeyword,
        array $blocks,
        array $imageAlt = [],
    ): array {
        $blocks = array_values(array_filter($blocks, fn (array $block): bool => (bool) ($block['visible'] ?? true)));
        $findings = [];

        $add = static function (string $level, string $area, string $message) use (&$findings): void {
            $findings[] = ['level' => $level, 'area' => $area, 'message' => $message];
        };

        // ---- Search -----------------------------------------------------------------------------
        $seoTitle = trim($seoTitle);

        if ($seoTitle === '') {
            $add(self::BAD, 'Search', 'No SEO title: the blue link in Google would fall back to the page title.');
        } elseif (Str::length($seoTitle) < 30) {
            $add(self::WARN, 'Search', 'The SEO title is '.Str::length($seoTitle).' characters — 30 to 60 gives search engines something to work with.');
        } elseif (Str::length($seoTitle) > 60) {
            $add(self::WARN, 'Search', 'The SEO title is '.Str::length($seoTitle).' characters — Google cuts it after about 60.');
        }

        $description = trim($seoDescription);

        if ($description === '') {
            $add(self::BAD, 'Search', 'No meta description yet: 120–165 characters is the space under the link.');
        } elseif (Str::length($description) < 120) {
            $add(self::WARN, 'Search', 'The meta description is '.Str::length($description).' characters — aim for 120 to 165.');
        } elseif (Str::length($description) > 165) {
            $add(self::WARN, 'Search', 'The meta description is '.Str::length($description).' characters — the last words will be cut off.');
        }

        $keyword = trim($focusKeyword);

        if ($keyword === '') {
            // Every imported page lacked one, so it is a suggestion rather than an issue: the list
            // should show the pages that need real work, not all 143.
            $add(self::INFO, 'Search', 'No focus keyword yet: name the search this page should answer, so its use can be checked.');
        } else {
            $headings = implode(' ', self::headings($blocks));
            $haystack = Str::lower($seoTitle.' '.$description.' '.$headings);

            // Every word of the phrase has to appear — not necessarily side by side. "interior design
            // lahore" is used by "Interior design in Lahore", which is how people write it.
            $missingWord = collect(preg_split('/\s+/', Str::lower($keyword)) ?: [])
                ->filter(fn (string $word): bool => $word !== '' && ! str_contains($haystack, $word))
                ->isNotEmpty();

            if ($missingWord) {
                $add(self::WARN, 'Search', 'The focus keyword “'.$keyword.'” does not appear in the title, the description or a heading.');
            }
        }

        // ---- Headings and accessibility ---------------------------------------------------------
        $h1 = self::h1Count($blocks);

        if ($h1 > 1) {
            $add(self::BAD, 'Accessibility', $h1.' H1 headings: a page has one, and the rest are H2. Two H1s split the page for screen readers and search engines.');
        }

        foreach ($blocks as $block) {
            if (($block['type'] ?? '') !== BlockType::Html->value) {
                continue;
            }

            $code = (string) ($block['data']['code'] ?? '');

            if (preg_match('/<h1\b/i', $code) === 1) {
                $add(self::WARN, 'Accessibility', 'The HTML block contains its own H1 — make it an H2 unless it is the page\'s only opening heading.');
            }

            if (preg_match('/<img\b(?![^>]*\balt\s*=)/i', $code) === 1) {
                $add(self::BAD, 'Accessibility', 'An image in the HTML block has no alt text.');
            }

            if (preg_match('/<a\b[^>]*>\s*(<[^>]+>\s*)*<\/a>/i', $code) === 1) {
                $add(self::WARN, 'Accessibility', 'The HTML block has a link with nothing in it — screen readers announce the address twice.');
            }
        }

        foreach ($blocks as $block) {
            $type = (string) ($block['type'] ?? '');
            $data = (array) ($block['data'] ?? []);

            foreach (self::imagesIn($data) as $imageId) {
                if ($imageId > 0 && trim((string) ($imageAlt[$imageId] ?? '')) === '') {
                    $add(self::BAD, 'Accessibility', 'An image in “'.self::name($block).'” has no alt text — set it in Website → Media.');
                    break;
                }
            }

            foreach (self::linksIn($type, $data) as [$label, $url]) {
                $hasLabel = trim($label) !== '';
                $hasUrl = trim($url) !== '' && trim($url) !== '#';

                if ($hasUrl && ! $hasLabel) {
                    $add(self::WARN, 'Accessibility', 'A link in “'.self::name($block).'” has an address but no label.');
                } elseif ($hasLabel && ! $hasUrl) {
                    $add(self::WARN, 'Accessibility', 'The button “'.$label.'” in “'.self::name($block).'” has nowhere to go.');
                }
            }
        }

        // ---- Content ----------------------------------------------------------------------------
        if (count($blocks) < 2) {
            $add(self::WARN, 'Content', 'One section only: a page needs an opening and at least one more section to read as finished.');
        }

        $hasOpening = collect($blocks)->contains(fn (array $block): bool => in_array(
            (string) ($block['type'] ?? ''),
            [BlockType::Hero->value, BlockType::Slider->value, BlockType::Intro->value, BlockType::Text->value],
            true,
        ));

        if (! $hasOpening) {
            $add(self::BAD, 'Content', 'No opening section: add a hero, a slider or an intro before the rest.');
        }

        if (trim($title) === '') {
            $add(self::BAD, 'Content', 'The page has no title.');
        }

        return $findings;
    }

    /**
     * How many H1s the page prints: a hero, a slider's first slide, or one inside an HTML block.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     */
    public static function h1Count(array $blocks): int
    {
        $count = 0;

        foreach ($blocks as $block) {
            $type = (string) ($block['type'] ?? '');
            $data = (array) ($block['data'] ?? []);

            if ($type === BlockType::Hero->value) {
                $count++;
            }

            if ($type === BlockType::Slider->value) {
                $slides = array_values(array_filter((array) ($data['slides'] ?? []), fn ($slide): bool => is_array($slide) && ($slide['image_id'] ?? null) !== null));

                if ($slides !== []) {
                    $count++;
                }
            }

            if ($type === BlockType::Html->value) {
                $count += preg_match_all('/<h1\b/i', (string) ($data['code'] ?? ''));
            }
        }

        return $count;
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @return list<string>
     */
    private static function headings(array $blocks): array
    {
        $headings = [];

        foreach ($blocks as $block) {
            $data = (array) ($block['data'] ?? []);

            foreach (['heading', 'eyebrow', 'name'] as $key) {
                if (is_string($data[$key] ?? null)) {
                    $headings[] = $data[$key];
                }
            }

            foreach (['items', 'slides', 'stages', 'rows'] as $key) {
                foreach ((array) ($data[$key] ?? []) as $row) {
                    if (is_array($row)) {
                        foreach (['heading', 'name', 'kicker', 'question', 'label'] as $field) {
                            if (is_string($row[$field] ?? null)) {
                                $headings[] = $row[$field];
                            }
                        }
                    }
                }
            }
        }

        return $headings;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private static function imagesIn(array $data): array
    {
        $ids = [];

        foreach (['image_id', 'og_image_id'] as $key) {
            if (is_numeric($data[$key] ?? null)) {
                $ids[] = (int) $data[$key];
            }
        }

        foreach (['images', 'logos', 'items'] as $key) {
            foreach ((array) ($data[$key] ?? []) as $row) {
                if (is_array($row) && is_numeric($row['id'] ?? null)) {
                    $ids[] = (int) $row['id'];
                }
            }
        }

        foreach ((array) ($data['slides'] ?? []) as $slide) {
            if (is_array($slide) && is_numeric($slide['image_id'] ?? null)) {
                $ids[] = (int) $slide['image_id'];
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Label/url pairs a visitor can click.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{0: string, 1: string}>
     */
    private static function linksIn(string $type, array $data): array
    {
        $links = [];

        if (in_array($type, [BlockType::Hero->value, BlockType::Slider->value], true)) {
            $links[] = [(string) ($data['primary_label'] ?? ''), (string) ($data['primary_url'] ?? '')];
            $links[] = [(string) ($data['secondary_label'] ?? ''), (string) ($data['secondary_url'] ?? '')];
        }

        if ($type === BlockType::Slider->value) {
            foreach ((array) ($data['slides'] ?? []) as $slide) {
                if (is_array($slide)) {
                    $links[] = [(string) ($slide['primary_label'] ?? ''), (string) ($slide['primary_url'] ?? '')];
                    $links[] = [(string) ($slide['secondary_label'] ?? ''), (string) ($slide['secondary_url'] ?? '')];
                }
            }
        }

        return array_values(array_filter($links, fn (array $pair): bool => $pair[0] !== '' || $pair[1] !== ''));
    }

    /**
     * The section's name for a message: its own label when it has one, otherwise its type.
     *
     * @param  array<string, mixed>  $block
     */
    private static function name(array $block): string
    {
        $label = trim((string) ($block['settings']['label'] ?? ''));

        if ($label !== '') {
            return $label;
        }

        return BlockType::tryFrom((string) ($block['type'] ?? ''))?->label() ?? 'a section';
    }
}
