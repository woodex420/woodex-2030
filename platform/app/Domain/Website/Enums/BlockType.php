<?php

declare(strict_types=1);

namespace App\Domain\Website\Enums;

use App\Domain\Website\Support\Columns;

/**
 * The section library (docs/09 §4). One type = one Blade component in resources/views/site/sections.
 */
enum BlockType: string
{
    case Hero = 'hero';

    /** V2-3: the v26 home hero — several full-bleed slides that rotate, each with its own photo and buttons. */
    case Slider = 'slider';
    case Intro = 'intro';
    case Text = 'text';
    case Services = 'services';
    case Process = 'process';
    case Gallery = 'gallery';
    case Faq = 'faq';
    case Related = 'related';
    case Form = 'form';
    case Reviews = 'reviews';
    case Specs = 'specs';
    case PriceTable = 'price_table';
    case Author = 'author';
    case Map = 'map';
    case Clients = 'clients';

    /** Phase 2: 2–4 leaf children in one section — text, photo or quote, no nesting. */
    case Columns = 'columns';

    /** V2-3 F7: the studio's people — picked from Website → Team, or written on the page. */
    case Team = 'team';

    /**
     * Hand-written HTML for the section no typed block covers (19 Oct, builder parity with the old
     * admin's HTML editor). Sanitised on save and again when it renders: scripts, on-event attributes
     * and javascript: URLs never reach a visitor.
     */
    case Html = 'html';

    public function label(): string
    {
        return match ($this) {
            self::Hero => 'Hero',
            self::Slider => 'Hero slider',
            self::Intro => 'Intro + key facts',
            self::Text => 'Text',
            self::Services => 'Services grid',
            self::Process => 'Process / stages',
            self::Gallery => 'Gallery',
            self::Faq => 'Questions & answers',
            self::Related => 'Related pages',
            self::Form => 'Enquiry form',
            self::Reviews => 'Reviews',
            self::Specs => 'Specifications table',
            self::PriceTable => 'Price bands',
            self::Author => 'Author',
            self::Map => 'Map + contact',
            self::Clients => 'Client logos',
            self::Columns => 'Columns',
            self::Team => 'Team',
            self::Html => 'HTML block',
        };
    }

    /**
     * One line that tells a non-technical editor what this section is for — the section library's blurb
     * (docs/12 §5.2). One declaration, two uses: the library panel and the editor header.
     */
    public function description(): string
    {
        return match ($this) {
            self::Hero => 'The opening: a big heading, a photo and the studio\'s first promise.',
            self::Slider => 'The v26 opening: full-height slides that rotate, each with its own photo, text and buttons.',
            self::Intro => 'A short paragraph plus key figures — what this page is about.',
            self::Text => 'A heading and free text: the workhorse for telling any story.',
            self::Services => 'A grid of services with a line each.',
            self::Process => 'The studio\'s stages, numbered from first call to handover.',
            self::Gallery => 'A grid of photographs picked from the media library.',
            self::Faq => 'Questions and answers — they also feed Google\'s rich results.',
            self::Related => 'Cards linking to other pages: services, cities, insights.',
            self::Form => 'The enquiry form with consent, wired straight into the CRM.',
            self::Reviews => 'Client reviews with names, ratings and dates.',
            self::Specs => 'A specifications table: what is included, area, timeline.',
            self::PriceTable => 'Price bands with what each band includes.',
            self::Author => 'The person behind the studio: photo, bio, credentials.',
            self::Map => 'The studio\'s address on a map, with phone and hours.',
            self::Clients => 'Client logos — the quiet trust signal.',
            self::Columns => 'Two to four side-by-side blocks — text, a photo or a quote. They stack on a phone.',
            self::Team => 'The studio\'s people, with photos, roles and short bios.',
            self::Html => 'Your own HTML, for a section the library does not cover. Scripts are removed when it is saved.',
        };
    }

    /**
     * The section library's groups (docs/12 §5.2): Hero first, then proof, content and the sections that
     * ask for the enquiry. `label()` names the section; this places it on the shelf.
     */
    public function category(): string
    {
        return match ($this) {
            self::Hero => 'Hero',
            self::Slider => 'Hero',
            self::Clients, self::Reviews, self::Gallery, self::Related, self::Author, self::Team => 'Proof',
            self::Intro, self::Text, self::Services, self::Process, self::Specs, self::PriceTable, self::Faq, self::Html => 'Content',
            self::Columns => 'Layout',
            self::Form, self::Map => 'Conversion',
        };
    }

    /**
     * Fields the editor shows, with the empty value used when a section is added.
     *
     * @return array<string, mixed>
     */
    public function fields(): array
    {
        return match ($this) {
            self::Hero => ['eyebrow' => '', 'heading' => '', 'text' => '', 'image_id' => null, 'primary_label' => 'Start your project', 'primary_url' => '#enquiry', 'secondary_label' => '', 'secondary_url' => '', 'facts' => []],
            self::Slider => ['slides' => []],
            self::Intro => ['eyebrow' => '', 'heading' => '', 'body' => '', 'image_id' => null, 'facts' => []],
            self::Text => ['heading' => '', 'body' => ''],
            self::Services => ['heading' => '', 'items' => []],
            self::Process => ['heading' => '', 'stages' => []],
            self::Gallery => ['heading' => '', 'images' => []],
            self::Faq => ['heading' => 'Common questions', 'items' => [], 'library_ids' => []],
            self::Related => ['heading' => 'Related', 'page_ids' => []],
            self::Form => ['heading' => 'Start your project', 'text' => '', 'show_contact' => true],
            self::Reviews => ['heading' => 'What our clients say', 'items' => [], 'library_ids' => []],
            self::Specs => ['heading' => 'Materials & finishes', 'rows' => []],
            self::PriceTable => ['heading' => 'Indicative investment', 'rows' => [], 'note' => ''],
            self::Author => ['name' => '', 'role' => '', 'bio' => '', 'image_id' => null],
            self::Map => ['heading' => 'Studio', 'embed_url' => ''],
            self::Clients => ['heading' => 'Trusted by', 'logos' => [], 'library_ids' => []],
            self::Columns => Columns::defaults(),
            self::Team => ['heading' => 'Our team', 'items' => [], 'library_ids' => []],
            self::Html => ['code' => ''],
        };
    }

    /**
     * The fields the page editor shows for this section (docs/09 §4).
     *
     * @return list<array{key: string, label: string, type: string, rows?: int, kind?: string, fields?: list<array{key: string, label: string, type: string, rows?: int}>}>
     */
    public function schema(): array
    {
        $nameValue = [
            ['key' => 'label', 'label' => 'Label', 'type' => 'text'],
            ['key' => 'value', 'label' => 'Value', 'type' => 'text'],
        ];

        return match ($this) {
            self::Hero => [
                ['key' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'],
                ['key' => 'heading', 'label' => 'Heading (the H1)', 'type' => 'textarea', 'rows' => 2],
                ['key' => 'text', 'label' => 'Intro', 'type' => 'textarea', 'rows' => 3],
                ['key' => 'image_id', 'label' => 'Background photo', 'type' => 'image'],
                ['key' => 'primary_label', 'label' => 'Button label', 'type' => 'text'],
                ['key' => 'primary_url', 'label' => 'Button link', 'type' => 'text'],
                ['key' => 'secondary_label', 'label' => 'Second button label', 'type' => 'text'],
                ['key' => 'secondary_url', 'label' => 'Second button link', 'type' => 'text'],
                ['key' => 'facts', 'label' => 'Key figures', 'type' => 'repeater', 'fields' => $nameValue],
            ],
            self::Slider => [
                ['key' => 'slides', 'label' => 'Slides', 'type' => 'repeater', 'fields' => [
                    ['key' => 'image_id', 'label' => 'Photo', 'type' => 'image'],
                    ['key' => 'kicker', 'label' => 'Small label', 'type' => 'text'],
                    ['key' => 'heading', 'label' => 'Heading', 'type' => 'textarea', 'rows' => 2],
                    ['key' => 'text', 'label' => 'Text', 'type' => 'textarea', 'rows' => 2],
                    ['key' => 'display', 'label' => 'Big display word (optional)', 'type' => 'text'],
                    ['key' => 'primary_label', 'label' => 'Button label', 'type' => 'text'],
                    ['key' => 'primary_url', 'label' => 'Button link', 'type' => 'text'],
                    ['key' => 'secondary_label', 'label' => 'Second button label', 'type' => 'text'],
                    ['key' => 'secondary_url', 'label' => 'Second button link', 'type' => 'text'],
                ]],
            ],
            self::Intro => [
                ['key' => 'eyebrow', 'label' => 'Eyebrow', 'type' => 'text'],
                ['key' => 'heading', 'label' => 'Heading', 'type' => 'text'],
                ['key' => 'body', 'label' => 'Text', 'type' => 'textarea', 'rows' => 6],
                ['key' => 'image_id', 'label' => 'Photo', 'type' => 'image'],
                ['key' => 'facts', 'label' => 'Key facts', 'type' => 'repeater', 'fields' => $nameValue],
            ],
            self::Text => [
                ['key' => 'heading', 'label' => 'Heading', 'type' => 'text'],
                ['key' => 'body', 'label' => 'Text', 'type' => 'textarea', 'rows' => 8],
            ],
            self::Services => [
                ['key' => 'heading', 'label' => 'Heading', 'type' => 'text'],
                ['key' => 'items', 'label' => 'Services', 'type' => 'repeater', 'fields' => [
                    ['key' => 'number', 'label' => 'Number', 'type' => 'text'],
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
                    ['key' => 'copy', 'label' => 'Description', 'type' => 'textarea', 'rows' => 2],
                    ['key' => 'url', 'label' => 'Link', 'type' => 'text'],
                ]],
            ],
            self::Process => [
                ['key' => 'heading', 'label' => 'Heading', 'type' => 'text'],
                ['key' => 'stages', 'label' => 'Stages', 'type' => 'repeater', 'fields' => [
                    ['key' => 'number', 'label' => 'Number', 'type' => 'text'],
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
                    ['key' => 'copy', 'label' => 'Description', 'type' => 'textarea', 'rows' => 2],
                ]],
            ],
            self::Gallery => [
                ['key' => 'heading', 'label' => 'Heading', 'type' => 'text'],
                ['key' => 'images', 'label' => 'Images', 'type' => 'repeater', 'fields' => [
                    ['key' => 'id', 'label' => 'Image', 'type' => 'image'],
                    ['key' => 'caption', 'label' => 'Caption', 'type' => 'text'],
                    ['key' => 'alt', 'label' => 'Alt text', 'type' => 'text'],
                ]],
            ],
            self::Faq => [
                ['key' => 'heading', 'label' => 'Heading', 'type' => 'text'],
                ['key' => 'library_ids', 'label' => 'From the shared FAQ library', 'type' => 'library', 'kind' => 'faq'],
                ['key' => 'items', 'label' => 'Questions', 'type' => 'repeater', 'fields' => [
                    ['key' => 'question', 'label' => 'Question', 'type' => 'text'],
                    ['key' => 'answer', 'label' => 'Answer', 'type' => 'textarea', 'rows' => 3],
                ]],
            ],
            self::Related => [
                ['key' => 'heading', 'label' => 'Heading', 'type' => 'text'],
                ['key' => 'page_ids', 'label' => 'Pages', 'type' => 'pages'],
            ],
            self::Form => [
                ['key' => 'heading', 'label' => 'Heading', 'type' => 'text'],
                ['key' => 'text', 'label' => 'Intro', 'type' => 'textarea', 'rows' => 3],
                ['key' => 'show_contact', 'label' => 'Show studio address, phone and hours', 'type' => 'bool'],
            ],
            self::Reviews => [
                ['key' => 'heading', 'label' => 'Heading', 'type' => 'text'],
                ['key' => 'library_ids', 'label' => 'From the shared testimonials library', 'type' => 'library', 'kind' => 'testimonial'],
                ['key' => 'items', 'label' => 'Reviews', 'type' => 'repeater', 'fields' => [
                    ['key' => 'text', 'label' => 'Review', 'type' => 'textarea', 'rows' => 3],
                    ['key' => 'name', 'label' => 'Client name', 'type' => 'text'],
                    ['key' => 'when', 'label' => 'When', 'type' => 'text'],
                    ['key' => 'source', 'label' => 'Source (Google, …)', 'type' => 'text'],
                ]],
            ],
            self::Specs => [
                ['key' => 'heading', 'label' => 'Heading', 'type' => 'text'],
                ['key' => 'rows', 'label' => 'Specifications', 'type' => 'repeater', 'fields' => $nameValue],
            ],
            self::PriceTable => [
                ['key' => 'heading', 'label' => 'Heading', 'type' => 'text'],
                ['key' => 'rows', 'label' => 'Bands', 'type' => 'repeater', 'fields' => $nameValue],
                ['key' => 'note', 'label' => 'Note under the table', 'type' => 'textarea', 'rows' => 2],
            ],
            self::Author => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
                ['key' => 'role', 'label' => 'Role', 'type' => 'text'],
                ['key' => 'bio', 'label' => 'Short bio', 'type' => 'textarea', 'rows' => 3],
                ['key' => 'image_id', 'label' => 'Photo', 'type' => 'image'],
            ],
            self::Map => [
                ['key' => 'heading', 'label' => 'Heading', 'type' => 'text'],
                ['key' => 'embed_url', 'label' => 'Map embed URL', 'type' => 'text'],
            ],
            self::Clients => [
                ['key' => 'heading', 'label' => 'Heading', 'type' => 'text'],
                ['key' => 'library_ids', 'label' => 'From the shared logos library', 'type' => 'library', 'kind' => 'logo'],
                ['key' => 'logos', 'label' => 'Logos', 'type' => 'repeater', 'fields' => [
                    ['key' => 'id', 'label' => 'Logo', 'type' => 'image'],
                    ['key' => 'alt', 'label' => 'Alt text', 'type' => 'text'],
                ]],
            ],
            self::Html => [
                ['key' => 'code', 'label' => 'HTML', 'type' => 'code', 'rows' => 14],
            ],
            // The editor draws this one itself: a column has its own kind and fields.
            self::Columns => [],
            self::Team => [
                ['key' => 'heading', 'label' => 'Heading', 'type' => 'text'],
                ['key' => 'library_ids', 'label' => 'From the studio\'s team (Website → Team)', 'type' => 'library', 'kind' => 'team'],
                ['key' => 'items', 'label' => 'Write someone on this page', 'type' => 'repeater', 'fields' => [
                    ['key' => 'id', 'label' => 'Photo', 'type' => 'image'],
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
                    ['key' => 'role', 'label' => 'Role', 'type' => 'text'],
                    ['key' => 'bio', 'label' => 'Bio', 'type' => 'textarea', 'rows' => 2],
                ]],
            ],
        };
    }

    public function view(): string
    {
        return 'site.sections.'.str_replace('_', '-', $this->value);
    }
}
