<?php

declare(strict_types=1);

namespace App\Domain\Website\Import;

use App\Domain\Website\Enums\BlockType;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Reads one page of the previous website into blocks (docs/09 §13, D-70).
 *
 * The old pages are static HTML with one CSS prefix per template (`id-`, `ct-`, `dx-`, `rv-` …) and a handful of
 * shared sections (`wx-short`, `wx-steps`, `wx-trust`, `wx-guides`, `wx-talk`), so the parser works from the content
 * of each section rather than from its classes: a section with an H1 is the hero, one with `.faq-item`s is the
 * questions block, a grid of headings with a paragraph each becomes services, an ordered list becomes the process.
 *
 * Image paths are returned as they appear in the old HTML, relative to the old site root (for example
 * `assets/img/img-c349a92a4ae0.webp`); the importer resolves them to media records.
 */
final class LegacyHtml
{
    private ?DOMDocument $document = null;

    private ?DOMXPath $path = null;

    public function __construct(private readonly string $html) {}

    /** The page title, as it was in the old `<title>`. */
    public function title(): string
    {
        return $this->text($this->first('//head/title'));
    }

    public function metaDescription(): ?string
    {
        $content = $this->first('//head/meta[@name="description"]')?->getAttribute('content') ?? '';

        return $content !== '' ? trim($content) : null;
    }

    /** The old canonical URL, used only to check the slug: the new site builds its own canonicals. */
    public function canonical(): ?string
    {
        $href = $this->first('//head/link[@rel="canonical"]')?->getAttribute('href') ?? '';

        return $href !== '' ? $href : null;
    }

    public function ogImage(): ?string
    {
        $content = $this->first('//head/meta[@property="og:image"]')?->getAttribute('content') ?? '';

        return $content !== '' ? $content : null;
    }

    /**
     * The sections of the page, in document order.
     *
     * @return list<array{type: BlockType, data: array<string, mixed>}>
     */
    public function blocks(): array
    {
        $blocks = [];

        foreach ($this->sectionsWithBlocks() as $section) {
            foreach ($section['blocks'] as $block) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /**
     * The same blocks, kept per top-level section (19 Oct, page templates): a template remembers the
     * page's rhythm — a soft section, then a dark one — as well as its order. `classes` is the raw
     * class attribute, so the caller can read the old site's own `…-soft` / `…-dark` markers.
     *
     * @return list<array{classes: string, blocks: list<array{type: BlockType, data: array<string, mixed>}>}>
     */
    public function sectionsWithBlocks(): array
    {
        $sections = [];

        foreach ($this->sections() as $section) {
            if ($this->isSharedSection($section)) {
                continue;
            }

            $blocks = [];

            foreach ($this->groups($section) as $group) {
                $block = $this->hero($group)
                    ?? $this->faq($group)
                    ?? $this->form($group)
                    ?? $this->gallery($group)
                    ?? $this->steps($group)
                    ?? $this->cards($group)
                    ?? $this->table($group)
                    ?? $this->related($group)
                    ?? $this->cta($group)
                    ?? $this->textBlock($group);

                if ($block !== null) {
                    $blocks[] = $block;
                }
            }

            if ($blocks !== []) {
                $sections[] = ['classes' => (string) $section->getAttribute('class'), 'blocks' => $blocks];
            }
        }

        return $sections;
    }

    /**
     * Every image of the page body, in document order, with the path as it was on the old site.
     *
     * @return list<array{src: string, alt: string, width: ?int, height: ?int}>
     */
    public function images(): array
    {
        $images = [];

        foreach ($this->query($this->body().'//img') as $image) {
            /** @var DOMElement $image */
            $src = $this->imageSource($image);

            if ($src === null || $this->isChromeImage($image)) {
                continue;
            }

            $images[] = [
                'src' => $src,
                'alt' => trim($image->getAttribute('alt')),
                'width' => $this->integer($image->getAttribute('width')),
                'height' => $this->integer($image->getAttribute('height')),
            ];
        }

        return $images;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Sections
    // ---------------------------------------------------------------------------------------------------------

    /**
     * The top-level sections of the page body. Nested sections (a card grid inside a section) are not returned: the
     * section that contains them decides the block.
     *
     * @return list<DOMElement>
     */
    private function sections(): array
    {
        $sections = [];

        foreach ($this->query($this->body().'//section') as $section) {
            /** @var DOMElement $section */
            if ($this->hasAncestorSection($section) || $this->hasAncestor($section, 'footer')) {
                continue;
            }

            $sections[] = $section;
        }

        if ($sections !== []) {
            return $sections;
        }

        // A page without <section>: take the block-level children of <main>.
        foreach ($this->query($this->body().'/*') as $child) {
            /** @var DOMElement $child */
            if (in_array($child->nodeName, ['div', 'article', 'section'], true)) {
                $sections[] = $child;
            }
        }

        return $sections;
    }

    private function hasAncestorSection(DOMElement $element): bool
    {
        return $this->hasAncestor($element, 'section');
    }

    /** Whether an element sits inside a <footer>, <nav> or <header> (some pages keep the footer inside <main>). */
    private function hasAncestor(DOMElement $element, string $tag): bool
    {
        $node = $element->parentNode;

        while ($node instanceof DOMElement) {
            if ($node->nodeName === $tag) {
                return true;
            }

            $node = $node->parentNode;
        }

        return false;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Block builders. Each returns null when the section is not of its kind, in the order tried by blocks().
    // ---------------------------------------------------------------------------------------------------------

    /** @return array{type: BlockType, data: array<string, mixed>}|null */
    private function hero(DOMElement $section): ?array
    {
        $heading = $this->text($this->first('.//h1', $section));

        if ($heading === '') {
            return null;
        }

        $paragraphs = $this->paragraphs($section);
        $lead = '';

        foreach ($paragraphs as $paragraph) {
            if (mb_strlen($paragraph) >= 60) {
                $lead = $paragraph;

                break;
            }
        }

        $buttons = $this->buttons($section);

        return $this->block(BlockType::Hero, [
            'eyebrow' => $this->eyebrow($section),
            'heading' => $heading,
            'text' => $lead,
            'image' => $this->sectionImage($section),
            'primary_label' => $buttons[0]['label'] ?? '',
            'primary_url' => $buttons[0]['url'] ?? '',
            'secondary_label' => $buttons[1]['label'] ?? '',
            'secondary_url' => $buttons[1]['url'] ?? '',
            'facts' => $this->facts($section),
        ]);
    }

    /** @return array{type: BlockType, data: array<string, mixed>}|null */
    private function faq(DOMElement $section): ?array
    {
        $items = [];

        foreach ($this->query('.//*[contains(@class, "faq-item")]', $section) as $item) {
            /** @var DOMElement $item */
            $question = $this->text($this->first('.//button|.//summary|.//h3', $item));
            $answer = $this->text($this->first('.//*[contains(@class, "faq-a")]', $item))
                ?: $this->text($this->first('.//p', $item));

            if ($question === '' || $answer === '') {
                continue;
            }

            $items[] = ['question' => rtrim($question, " +\u{2212}"), 'answer' => $answer];
        }

        // The twelve older pages use <details> instead of the faq- classes.
        foreach ($this->query('.//details', $section) as $item) {
            /** @var DOMElement $item */
            $question = $this->text($this->first('./summary|.//summary', $item));
            $answer = $this->text($this->first('.//p', $item));

            if ($question === '' || $answer === '') {
                continue;
            }

            $items[] = ['question' => $question, 'answer' => $answer];
        }

        if ($items === []) {
            return null;
        }

        return $this->block(BlockType::Faq, [
            'heading' => $this->heading($section),
            'items' => $items,
        ]);
    }

    /** @return array{type: BlockType, data: array<string, mixed>}|null */
    private function form(DOMElement $section): ?array
    {
        if ($this->first('.//form', $section) === null) {
            return null;
        }

        $paragraphs = $this->paragraphs($section);

        return $this->block(BlockType::Form, [
            'heading' => $this->heading($section),
            'text' => $paragraphs[0] ?? '',
            'show_contact' => true,
        ]);
    }

    /** @return array{type: BlockType, data: array<string, mixed>}|null */
    private function gallery(DOMElement $section): ?array
    {
        $images = [];

        foreach ($this->query('.//img', $section) as $image) {
            /** @var DOMElement $image */
            $src = $this->imageSource($image);

            if ($src === null || $this->isChromeImage($image)) {
                continue;
            }

            $caption = $this->text($this->first('ancestor::figure[1]/figcaption', $image));

            $images[] = ['src' => $src, 'alt' => trim($image->getAttribute('alt')), 'caption' => $caption];
        }

        // A card grid (guides, projects) also holds many images: it is links with text, not a gallery.
        $headings = $this->query('.//h3|.//h4', $section)->length;

        if (count($images) < 3 || $headings >= 3 || count($this->internalLinks($section)) >= 3) {
            return null;
        }

        return $this->block(BlockType::Gallery, [
            'heading' => $this->heading($section),
            'images' => $images,
        ]);
    }

    /** @return array{type: BlockType, data: array<string, mixed>}|null */
    private function steps(DOMElement $section): ?array
    {
        $list = $this->first('.//*[contains(@class, "wx-steps")]', $section) ?? $this->first('.//ol', $section);

        if ($list === null) {
            return null;
        }

        $stages = [];

        foreach ($this->query('.//li', $list) as $index => $item) {
            /** @var DOMElement $item */
            $name = $this->text($this->first('.//h3|.//h4|.//strong|.//b', $item));
            $copy = $this->text($this->first('.//p', $item));

            if ($name === '' && $copy === '') {
                continue;
            }

            if ($name === '') {
                $name = $copy;
                $copy = '';
            }

            $stages[] = [
                'number' => $this->text($this->first('.//*[contains(@class, "num")]', $item)) ?: (string) ($index + 1),
                'name' => $name,
                'copy' => $copy,
            ];
        }

        if (count($stages) < 2) {
            return null;
        }

        return $this->block(BlockType::Process, [
            'heading' => $this->heading($section),
            'stages' => $stages,
        ]);
    }

    /**
     * A grid of cards: a heading with a line of copy under it. With a photo beside it the grid reads as key facts,
     * so it becomes an intro; without one it becomes services (or the process, when the section says so).
     *
     * @return array{type: BlockType, data: array<string, mixed>}|null
     */
    private function cards(DOMElement $section): ?array
    {
        $items = [];

        foreach ($this->query('.//h3|.//h4', $section) as $index => $heading) {
            /** @var DOMElement $heading */
            $name = $this->text($heading);
            $copy = $this->followingText($heading);

            if ($name === '') {
                continue;
            }

            $items[] = [
                'number' => $this->precedingNumber($heading) ?: (string) ($index + 1),
                'name' => $name,
                'copy' => $copy,
                'url' => $this->cardUrl($heading),
            ];
        }

        $pairs = $items === [] || count($items) < 3 ? $this->labelPairs($section) : [];
        $items = count($items) >= 3 ? $items : [];

        if ($items === [] && $pairs === []) {
            return null;
        }

        $image = $this->sectionImage($section);

        if ($pairs !== [] && $image !== null) {
            return $this->block(BlockType::Intro, [
                'eyebrow' => '',
                'heading' => $this->heading($section),
                'body' => $this->bodyText($section, true),
                'image' => $image,
                'facts' => $pairs,
            ]);
        }

        if ($pairs !== []) {
            return $this->block(BlockType::Specs, [
                'heading' => $this->heading($section),
                'rows' => $pairs,
            ]);
        }

        if ($this->isProcess($section)) {
            return $this->block(BlockType::Process, [
                'heading' => $this->heading($section),
                'stages' => array_map(
                    fn (array $item): array => ['number' => $item['number'], 'name' => $item['name'], 'copy' => $item['copy']],
                    $items,
                ),
            ]);
        }

        return $this->block(BlockType::Services, [
            'heading' => $this->heading($section),
            'items' => $items,
        ]);
    }

    /**
     * The parts of a section that each become a block.
     *
     * An article page keeps the whole body in one `<section>`, so a long one is split at its H2s into one group per
     * sub-heading. Heroes and card grids are never split: their headings belong together.
     *
     * @return list<DOMElement>
     */
    private function groups(DOMElement $section): array
    {
        $headings = $this->query('.//h2', $section);
        $paragraphs = $this->query('.//p', $section);

        if ($headings->length < 2 || $paragraphs->length < 6 || $this->first('.//h1', $section) !== null) {
            return [$section];
        }

        if ($this->cards($section) !== null) {
            return [$section];
        }

        $groups = [];

        foreach ($headings as $index => $heading) {
            /** @var DOMElement $heading */
            $next = $headings->item($index + 1) instanceof DOMElement ? $headings->item($index + 1) : null;
            $groups[] = $this->range($section, $heading, $next);
        }

        return $groups;
    }

    /**
     * A wrapper holding the content from one heading up to the next one, so a group can be read like a section.
     *
     * The nodes are cloned (the document stays intact for the queries that follow), and only the highest nodes of
     * the range are copied: cloning a container together with each of its children would duplicate the content.
     *
     * @param  DOMElement|null  $start  nodes before it are skipped (null: start at the beginning of the container)
     * @param  DOMElement|null  $stop  the next heading, which belongs to the following group
     */
    private function range(DOMElement $container, ?DOMElement $start, ?DOMElement $stop): DOMElement
    {
        $wrapper = ($this->document ?? new DOMDocument)->createElement('div');
        $started = false;

        foreach ($container->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $holdsStop = $stop !== null && ($child->isSameNode($stop) || $this->contains($child, $stop));

            if ($holdsStop) {
                // Part of this node still belongs to this group: everything in it before the next heading.
                if (! $child->isSameNode($stop)) {
                    $head = $this->range($child, $this->contains($child, $start) ? $start : null, $stop);

                    if ($head->childNodes->length > 0) {
                        $wrapper->appendChild($head);
                    }
                }

                break;
            }

            if ($started) {
                $wrapper->appendChild($child->cloneNode(true));

                continue;
            }

            if ($start !== null && $this->contains($child, $start)) {
                // The heading sits deeper inside this node: take its part only, not the whole subtree.
                $started = true;
                $wrapper->appendChild($this->range($child, $start, $stop));

                continue;
            }

            if ($start === null || $child->isSameNode($start)) {
                $started = true;
                $wrapper->appendChild($child->cloneNode(true));
            }
        }

        return $wrapper;
    }

    /** Whether a node sits somewhere inside an element (null is contained by nothing). */
    private function contains(DOMElement $haystack, ?DOMNode $needle): bool
    {
        if ($needle === null) {
            return false;
        }

        return $this->containsNode($haystack, $needle);
    }

    private function containsNode(DOMElement $haystack, DOMNode $needle): bool
    {
        $node = $needle->parentNode;

        while ($node instanceof DOMElement) {
            if ($node->isSameNode($haystack)) {
                return true;
            }

            $node = $node->parentNode;
        }

        return false;
    }

    /**
     * A comparison or cost table: the first column labels the row, the rest is the value.
     *
     * @return array{type: BlockType, data: array<string, mixed>}|null
     */
    private function table(DOMElement $section): ?array
    {
        $table = $this->first('.//table', $section);

        if ($table === null) {
            return null;
        }

        $rows = [];

        foreach ($this->query('.//tr', $table) as $row) {
            /** @var DOMElement $row */
            $cells = [];
            $head = 0;

            foreach ($this->query('./th|./td', $row) as $cell) {
                /** @var DOMElement $cell */
                if ($cell->nodeName === 'th') {
                    $head++;
                }

                $text = $this->text($cell);

                if ($text !== '') {
                    $cells[] = $text;
                }
            }

            if ($head > 0 && $head === count($cells)) {
                continue; // the header row
            }

            if (count($cells) >= 2) {
                $rows[] = ['label' => $cells[0], 'value' => implode(' Â· ', array_slice($cells, 1))];
            }
        }

        if (count($rows) < 2) {
            return null;
        }

        return $this->block(BlockType::PriceTable, [
            'heading' => $this->heading($section),
            'rows' => array_slice($rows, 0, 12),
            'note' => '',
        ]);
    }

    /**
     * The links of a section that point to other pages of the site, in document order and without duplicates.
     *
     * @return list<string>
     */
    private function internalLinks(DOMElement $section): array
    {
        $urls = [];

        foreach ($this->query('.//a[@href]', $section) as $link) {
            /** @var DOMElement $link */
            $href = $link->getAttribute('href');

            if (preg_match('#^/[a-z0-9][a-z0-9\-/]*$#', $href) === 1 && ! in_array($href, $urls, true)) {
                $urls[] = $href;
            }
        }

        return $urls;
    }

    /**
     * A list of links to other pages of the site ("Also served from the Lahore studio").
     *
     * @return array{type: BlockType, data: array<string, mixed>}|null
     */
    private function related(DOMElement $section): ?array
    {
        $urls = $this->internalLinks($section);

        // A section with real copy of its own is text, not a list of links.
        $copy = implode(' ', $this->paragraphs($section));

        if (count($urls) < 2 || $this->heading($section) === '' || mb_strlen($copy) > 160) {
            return null;
        }

        return $this->block(BlockType::Related, [
            'heading' => $this->heading($section),
            'urls' => array_slice($urls, 0, 8),
        ]);
    }

    /**
     * The closing section of a page: a heading with the studio phone and hours, and no form.
     *
     * @return array{type: BlockType, data: array<string, mixed>}|null
     */
    private function cta(DOMElement $section): ?array
    {
        $text = $this->text($section);

        if ($this->heading($section) === '' || preg_match('/\+92|\b03\d{2}[ -]?\d{7}\b|@woodex\.com/i', $text) !== 1) {
            return null;
        }

        $paragraphs = $this->paragraphs($section);

        return $this->block(BlockType::Form, [
            'heading' => $this->heading($section),
            'text' => $paragraphs[0] ?? '',
            'show_contact' => true,
        ]);
    }

    /** @return array{type: BlockType, data: array<string, mixed>}|null */
    private function textBlock(DOMElement $section): ?array
    {
        $heading = $this->heading($section);
        $image = $this->sectionImage($section);
        $facts = $this->facts($section);
        // A section whose lists became key figures must not repeat them as body copy.
        $body = $this->bodyText($section, $facts !== []);
        $eyebrow = '';

        $eyebrow = $this->eyebrow($section);

        // "In short", "Where we work": a short opening line with no class and no heading of its own is an eyebrow.
        if ($eyebrow === '' && $heading === '') {
            $lines = explode("\n\n", $body, 2);

            if (count($lines) === 2 && mb_strlen($lines[0]) <= 60) {
                $eyebrow = $lines[0];
                $body = $lines[1];
            }
        }

        if ($heading === '' && $body === '' && $facts === []) {
            return null;
        }

        // A section with its own photo and key figures reads better as an intro than as flat text.
        if ($image !== null || count($facts) >= 2) {
            return $this->block(BlockType::Intro, [
                'eyebrow' => $eyebrow,
                'heading' => $heading,
                'body' => $body,
                'image' => $image,
                'facts' => $facts,
            ]);
        }

        return $this->block(BlockType::Text, ['heading' => $heading, 'body' => $body]);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Reading helpers
    // ---------------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     * @return array{type: BlockType, data: array<string, mixed>}
     */
    private function block(BlockType $type, array $data): array
    {
        return ['type' => $type, 'data' => $data];
    }

    /** The first element matching an XPath query, or null. */
    private function first(string $query, ?DOMNode $context = null): ?DOMElement
    {
        $nodes = $this->query($query, $context);

        /** @var DOMElement|null $node */
        $node = $nodes->item(0);

        return $node;
    }

    /** @return \DOMNodeList<DOMNode> */
    private function query(string $query, ?DOMNode $context = null): \DOMNodeList
    {
        if ($this->path === null) {
            $document = new DOMDocument;
            $previous = libxml_use_internal_errors(true);
            // The old markup is not XML-clean (unclosed <p>, inline styles, emojis): load it as HTML and ignore noise.
            $document->loadHTML('<?xml encoding="utf-8"?>'.$this->html, LIBXML_NOERROR | LIBXML_NOWARNING);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            $this->document = $document;
            $this->path = new DOMXPath($document);
        }

        if ($context !== null) {
            return $this->path->query($query, $context) ?: new \DOMNodeList;
        }

        return $this->path->query($query) ?: new \DOMNodeList;
    }

    /** The element whose content carries the page: <main>, or <body> when there is no <main>. */
    private function body(): string
    {
        return $this->first('//main') !== null ? '//main' : '//body';
    }

    /** Visible text of a node with whitespace collapsed, or '' when there is no node. */
    private function text(?DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', (string) $node->textContent));
    }

    /** @return list<string> */
    private function paragraphs(DOMElement $section): array
    {
        $paragraphs = [];

        foreach ($this->query('.//p', $section) as $paragraph) {
            /** @var DOMElement $paragraph */
            $text = $this->text($paragraph);

            if ($text !== '' && ! $this->isLabel($paragraph)) {
                $paragraphs[] = $text;
            }
        }

        return $paragraphs;
    }

    /** Small uppercase labels ("In short", "Cost guide") are eyebrows, not body copy. */
    private function isLabel(DOMElement $paragraph): bool
    {
        $class = $paragraph->getAttribute('class');

        return $class !== '' && (bool) preg_match('/label|kicker|eyebrow|crumb|caption/i', $class);
    }

    private function heading(DOMElement $section): string
    {
        return $this->text($this->first('.//h2', $section));
    }

    /**
     * The small line above a heading ("Kitchens", "In short"): the element the old pages marked as a label, or a
     * short first paragraph when there is no such element.
     */
    private function eyebrow(DOMElement $section): string
    {
        $label = $this->first('.//p[contains(@class, "label") or contains(@class, "kicker") or contains(@class, "eyebrow")]', $section)
            ?? $this->first('.//span[contains(@class, "label") or contains(@class, "kicker") or contains(@class, "eyebrow")]', $section);

        $text = $this->text($label);

        return $text !== '' && mb_strlen($text) <= 60 ? $text : '';
    }

    /**
     * The text of a section: its paragraphs, then its lists as "- item" lines.
     *
     * Lists are left out when they were already turned into key figures, so the same sentence never appears twice.
     */
    private function bodyText(DOMElement $section, bool $withoutLists = false): string
    {
        $parts = $this->paragraphs($section);

        if (! $withoutLists) {
            foreach ($this->query('.//ul/li', $section) as $item) {
                $text = $this->text($item);

                if ($text !== '' && ! in_array($text, $parts, true)) {
                    $parts[] = '- '.$text;
                }
            }
        }

        return implode("\n\n", array_slice($parts, 0, 12));
    }

    /** @return list<array{label: string, value: string}> */
    private function facts(DOMElement $section): array
    {
        $facts = [];

        foreach ($this->query('.//dl', $section) as $list) {
            if (! $list instanceof DOMElement) {
                continue;
            }

            foreach ($this->query('./div|.//div', $list) as $row) {
                if (! $row instanceof DOMElement) {
                    continue;
                }

                $label = $this->text($this->first('.//dt', $row));
                $value = $this->text($this->first('.//dd', $row));

                if ($label !== '' && $value !== '') {
                    $facts[] = ['label' => $label, 'value' => $value];
                }
            }
        }

        if ($facts !== []) {
            return array_slice($facts, 0, 6);
        }

        foreach ($this->query('.//li', $section) as $item) {
            $text = $this->text($item);

            if ($text !== '') {
                $facts[] = ['label' => '', 'value' => $text];
            }
        }

        return array_slice($facts, 0, 4);
    }

    /**
     * The buttons (calls to action) of a section: a link whose class contains "btn".
     *
     * @return list<array{label: string, url: string}>
     */
    private function buttons(DOMElement $section): array
    {
        $buttons = [];

        foreach ($this->query('.//a[contains(@class, "btn")]', $section) as $link) {
            /** @var DOMElement $link */
            $label = $this->text($link);

            if ($label === '') {
                continue;
            }

            $buttons[] = ['label' => $label, 'url' => $link->getAttribute('href')];
        }

        return array_slice($buttons, 0, 2);
    }

    /** The first real photograph of a section, as a path relative to the old site root. */
    private function sectionImage(DOMElement $section): ?string
    {
        foreach ($this->query('.//img', $section) as $image) {
            /** @var DOMElement $image */
            if ($this->isChromeImage($image)) {
                continue;
            }

            $src = $this->imageSource($image);

            if ($src !== null) {
                return $src;
            }
        }

        return null;
    }

    /** Icons, logos and avatars are not content: they would become images with empty alt text. */
    private function isChromeImage(DOMElement $image): bool
    {
        $class = $image->getAttribute('class').' '.$image->getAttribute('src');

        return (bool) preg_match('/logo|icon|favicon|avatar|sprite|placeholder|avatar/i', $class);
    }

    /**
     * The image path of an <img>, relative to the old site root (`assets/img/foo.webp`).
     *
     * `srcset` is ignored on purpose: `src` already holds the largest variant on these pages.
     */
    private function imageSource(DOMElement $image): ?string
    {
        $src = trim($image->getAttribute('src'));

        if ($src === '' || str_starts_with($src, 'data:') || preg_match('/\.(svg|gif)$/i', $src) === 1) {
            return null;
        }

        return ltrim((string) preg_replace('#^https?://[^/]+#', '', $src), '/');
    }

    /** The line of copy under a heading inside its own card ("<strong>Name</strong><span>Copy</span>"). */
    private function followingText(DOMElement $node): string
    {
        $context = $node->parentNode;

        if (! $context instanceof DOMElement) {
            return '';
        }

        foreach ($this->query('.//p|.//span|.//div', $context) as $candidate) {
            /** @var DOMElement $candidate */
            if ($this->first('.//strong|.//h3|.//h4|.//p|.//span', $candidate) !== null) {
                continue; // a wrapper around several cards, not the copy of this one
            }

            $text = $this->text($candidate);

            if ($text !== '' && $text !== $this->text($node)) {
                return $text;
            }
        }

        return '';
    }

    /**
     * Name and copy pairs of a card grid that uses <strong> instead of headings ("<strong>Core discipline</strong>
     * <span>Renovation sits alongside interior design and fit-out.</span>").
     *
     * @return list<array{label: string, value: string}>
     */
    private function labelPairs(DOMElement $section): array
    {
        $pairs = [];

        foreach ($this->query('.//strong|.//b', $section) as $heading) {
            /** @var DOMElement $heading */
            $label = $this->text($heading);
            $value = $this->followingText($heading);

            if ($label === '' || $value === '' || mb_strlen($label) > 60) {
                continue;
            }

            $pairs[] = ['label' => $label, 'value' => $value];
        }

        return count($pairs) >= 3 ? array_slice($pairs, 0, 8) : [];
    }

    /** The number printed above a card heading ("01"), when the old page had one. */
    private function precedingNumber(DOMElement $node): string
    {
        foreach ($this->query('preceding-sibling::*', $node) as $sibling) {
            /** @var DOMElement $sibling */
            $text = $this->text($sibling);

            if (preg_match('/^\d{1,2}$/', $text) === 1) {
                return $text;
            }
        }

        return '';
    }

    /** The page a card links to, when the card is a link to another page of the site. */
    private function cardUrl(DOMElement $node): string
    {
        $context = $node->parentNode;

        while ($context instanceof DOMElement) {
            if ($context->nodeName === 'a') {
                return $context->getAttribute('href');
            }

            $context = $context->parentNode;
        }

        return '';
    }

    /**
     * Sections that carry the same copy on every page of the old site ("Why Woodex", "Related guides"). The new site
     * renders those from shared components, and importing them would copy identical text onto 89 pages.
     */
    private function isSharedSection(DOMElement $section): bool
    {
        $class = $section->getAttribute('class').' '.$section->getAttribute('id');

        return preg_match('/trust|guides|related|footer|breadcrumb|nav-|testimonial|client/i', $class) === 1;
    }

    private function isProcess(DOMElement $section): bool
    {
        $class = $section->getAttribute('class').' '.$section->getAttribute('id');

        return preg_match('/process|steps|stages|how-we-work|method/i', $class) === 1;
    }

    private function integer(string $value): ?int
    {
        return $value !== '' && ctype_digit($value) ? (int) $value : null;
    }
}
