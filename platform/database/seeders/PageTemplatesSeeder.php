<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Website\Enums\BlockType;
use App\Domain\Website\Enums\PageType;
use App\Domain\Website\Models\PageTemplate;
use Illuminate\Database\Seeder;

/**
 * The studio's page layouts (V2-3 F5, docs/14 §5a), carried over from the v1 website's template
 * system: the layout decides which sections a page opens with and in what order — the text is
 * always the page's own. Idempotent: templates keep their identity by name.
 */
class PageTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::all() as $template) {
            PageTemplate::query()->updateOrCreate(
                ['name' => $template['name']],
                [
                    'page_type' => $template['page_type'],
                    'description' => $template['description'],
                    'is_default' => $template['is_default'],
                    'sections' => $template['sections'],
                ],
            );
        }

        // A template the seeder no longer carries is gone: the generated set is named after the page
        // it came from, and an older naming would otherwise leave a duplicate of every layout behind.
        PageTemplate::query()->whereNull('created_by')->whereNotIn('name', array_column(self::all(), 'name'))->delete();
    }

    /**
     * The eight hand-written layouts plus the fifty the frontend supplied (19 Oct). The generated
     * ones carry their own page type and never claim `is_default`, so a page type still opens with
     * the layout the studio chose for it.
     *
     * @return list<array{name: string, page_type: PageType, description: string, is_default: bool, sections: list<array{type: string, data: array<string, mixed>, settings?: array<string, string>}>}>
     */
    public static function all(): array
    {
        $hand = self::templates();
        $derived = require database_path('seeders/data/frontend-templates.php');

        // The generated names are the pages' own titles; say where each layout came from, so a name
        // repeated in two page types (a city page and its service twin) can still be told apart.
        $derived = array_map(function (array $template): array {
            $template['name'] = $template['name'].' — layout of '.$template['source'];

            return $template;
        }, $derived);

        foreach ($derived as $template) {
            $hand[] = [
                'name' => $template['name'],
                'page_type' => PageType::from($template['page_type']),
                'description' => $template['description'],
                'is_default' => false,
                'sections' => $template['sections'],
            ];
        }

        return $hand;
    }

    /**
     * @return list<array{name: string, page_type: PageType, description: string, is_default: bool, sections: list<array{type: string, data: array<string, mixed>, settings?: array<string, string>}>}>
     */
    public static function templates(): array
    {
        $section = static fn (BlockType $type, array $data = []): array => ['type' => $type->value, 'data' => $data + $type->fields()];

        return [
            [
                'name' => 'Service page — full',
                'page_type' => PageType::Service,
                'description' => 'The complete service story: hero, intro, what is delivered, how it runs, specifications, photos, questions and the enquiry form.',
                'is_default' => true,
                'sections' => [
                    $section(BlockType::Hero),
                    $section(BlockType::Intro, ['heading' => 'What this service is']),
                    $section(BlockType::Services, ['heading' => 'What we deliver']),
                    $section(BlockType::Process, ['heading' => 'How the work runs']),
                    $section(BlockType::Specs),
                    $section(BlockType::Gallery),
                    $section(BlockType::Faq),
                    $section(BlockType::Form),
                ],
            ],
            [
                'name' => 'Service page — short',
                'page_type' => PageType::Service,
                'description' => 'A quick service page: hero, one text section, questions and the enquiry form.',
                'is_default' => false,
                'sections' => [
                    $section(BlockType::Hero),
                    $section(BlockType::Text, ['heading' => 'In short']),
                    $section(BlockType::Faq),
                    $section(BlockType::Form),
                ],
            ],
            [
                'name' => 'City page',
                'page_type' => PageType::City,
                'description' => 'Where the studio works: hero, city intro, the services, photos, reviews, questions and the enquiry form.',
                'is_default' => true,
                'sections' => [
                    $section(BlockType::Hero),
                    $section(BlockType::Intro, ['heading' => 'Working in this city']),
                    $section(BlockType::Services, ['heading' => 'What we do here']),
                    $section(BlockType::Gallery),
                    $section(BlockType::Reviews),
                    $section(BlockType::Faq),
                    $section(BlockType::Form),
                ],
            ],
            [
                'name' => 'Insight article',
                'page_type' => PageType::Insight,
                'description' => 'A cost guide or article: hero, the text, then questions.',
                'is_default' => true,
                'sections' => [
                    $section(BlockType::Hero),
                    $section(BlockType::Text, ['heading' => 'The guide']),
                    $section(BlockType::Faq),
                ],
            ],
            [
                'name' => 'Project study',
                'page_type' => PageType::Project,
                'description' => 'A portfolio study: hero, the photos, the story, specifications, reviews and the enquiry form.',
                'is_default' => true,
                'sections' => [
                    $section(BlockType::Hero),
                    $section(BlockType::Gallery),
                    $section(BlockType::Text, ['heading' => 'The project']),
                    $section(BlockType::Specs),
                    $section(BlockType::Reviews),
                    $section(BlockType::Form),
                ],
            ],
            [
                'name' => 'Home — v26',
                'page_type' => PageType::Home,
                'description' => 'The v26 home: slider, services, process, photos, reviews, questions and the enquiry form.',
                'is_default' => true,
                'sections' => [
                    $section(BlockType::Slider),
                    $section(BlockType::Services, ['heading' => 'One connected practice']),
                    $section(BlockType::Process, ['heading' => 'How we work']),
                    $section(BlockType::Gallery, ['heading' => 'Recent work']),
                    $section(BlockType::Reviews),
                    $section(BlockType::Faq),
                    $section(BlockType::Form),
                ],
            ],
            [
                'name' => 'Landing page — enquiry',
                'page_type' => PageType::Booking,
                'description' => 'A page built to convert: hero, the enquiry form, then questions.',
                'is_default' => true,
                'sections' => [
                    $section(BlockType::Hero),
                    $section(BlockType::Form),
                    $section(BlockType::Faq),
                ],
            ],
            [
                'name' => 'About the studio',
                'page_type' => PageType::About,
                'description' => 'The studio story: hero, intro, the people, how it works, reviews and the enquiry form.',
                'is_default' => true,
                'sections' => [
                    $section(BlockType::Hero),
                    $section(BlockType::Intro, ['heading' => 'The studio, in short']),
                    $section(BlockType::Team, ['heading' => 'The people']),
                    $section(BlockType::Author),
                    $section(BlockType::Process, ['heading' => 'How we work']),
                    $section(BlockType::Reviews),
                    $section(BlockType::Form),
                ],
            ],
        ];
    }
}
