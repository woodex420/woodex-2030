<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Website\Enums\PageType;
use App\Domain\Website\Import\LegacyHtml;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Turns the approved frontend's own pages into page templates (19 Oct).
 *
 * Admin v2.1 pro shipped a library of fifty templates. That library is a JavaScript file in a branch
 * that no longer exists, so rather than invent fifty layouts this reads the pages the studio has
 * actually approved — `public/<slug>/index.html` — through the same parser the legacy importer uses,
 * and keeps one template per distinct section rhythm. The section order is the page's own, the
 * background comes from the page's own soft/dark markers, and the only copy kept is each section's
 * heading: a template decides the layout, never what a page says.
 *
 * `--write` regenerates the data file the seeder reads; without it the command only reports.
 */
class FrontendTemplates extends Command
{
    protected $signature = 'woodex:frontend-templates
        {--write : Rewrite database/seeders/data/frontend-templates.php}
        {--limit=42 : How many layouts to keep (the seeder adds the eight hand-written ones)}';

    protected $description = 'Derive page templates from the approved frontend pages (safe to re-run).';

    public function handle(): int
    {
        $root = (string) config('website.frontend_root', public_path());
        $files = glob($root.'/*/index.html') ?: [];

        if ($files === []) {
            $this->components->error('No pages found under '.$root.'/*/index.html.');

            return self::FAILURE;
        }

        $candidates = [];

        foreach ($files as $file) {
            $slug = '/'.basename(dirname($file)).'/';
            $candidate = $this->candidate($file, $slug);

            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        usort($candidates, fn (array $a, array $b): int => strcmp($a['source'], $b['source']));

        // One template per section rhythm first — that is where the real variety is. The rest are
        // second, third and fourth pages of the same rhythm, which still read differently because
        // their headings do; no rhythm appears more than four times.
        $chosen = [];
        $seen = [];

        foreach ($candidates as $candidate) {
            if (! isset($seen[$candidate['signature']])) {
                $seen[$candidate['signature']] = 1;
                $chosen[] = $candidate;
            }
        }

        $covered = array_flip(array_column($chosen, 'type'));
        $rest = array_values(array_filter($candidates, fn (array $candidate): bool => ! in_array($candidate, $chosen, true)));

        usort($rest, fn (array $a, array $b): int => [isset($covered[$a['type']]), count($b['sections']), $a['source']]
            <=> [isset($covered[$b['type']]), count($a['sections']), $b['source']]);

        $limit = max(1, (int) $this->option('limit'));
        $perSignature = $seen;

        foreach ($rest as $candidate) {
            if (count($chosen) >= $limit) {
                break;
            }

            if (($perSignature[$candidate['signature']] ?? 0) >= 4) {
                continue;
            }

            $chosen[] = $candidate;
            $perSignature[$candidate['signature']] = ($perSignature[$candidate['signature']] ?? 0) + 1;
        }

        usort($chosen, fn (array $a, array $b): int => [$a['type'], $a['source']] <=> [$b['type'], $b['source']]);

        $this->table(
            ['Page type', 'Template', 'Sections', 'From'],
            array_map(fn (array $template): array => [
                $template['type'], $template['name'], count($template['sections']), $template['source'],
            ], $chosen),
        );

        $this->components->info(count($chosen).' layouts from '.count($files).' frontend pages.');

        if (! $this->option('write')) {
            $this->line('Nothing written. Pass --write to regenerate the data file.');

            return self::SUCCESS;
        }

        $path = database_path('seeders/data/frontend-templates.php');
        @mkdir(dirname($path), 0775, true);

        file_put_contents($path, $this->php($chosen));

        $this->components->info('Written to '.$path.'. Run: php artisan db:seed --class=PageTemplatesSeeder');

        return self::SUCCESS;
    }

    /**
     * @return array{name: string, source: string, type: string, signature: string, description: string, sections: list<array{type: string, data: array<string, string>, settings: array<string, string>}>}|null
     */
    private function candidate(string $file, string $slug): ?array
    {
        $html = new LegacyHtml((string) file_get_contents($file));
        $blocks = [];
        $signature = [];

        foreach ($html->sectionsWithBlocks() as $section) {
            $background = $this->background($section['classes']);

            foreach ($section['blocks'] as $block) {
                $heading = is_string($block['data']['heading'] ?? null) ? trim((string) $block['data']['heading']) : '';

                $blocks[] = [
                    'type' => $block['type']->value,
                    'data' => $heading !== '' ? ['heading' => $heading] : [],
                    'settings' => $background !== '' ? ['background' => $background] : [],
                ];

                $signature[] = $block['type']->value.':'.$background;
            }
        }

        if (count($blocks) < 3) {
            return null;
        }

        $title = trim((string) $html->title());
        $title = trim(preg_split('/\s+[|·—]\s+/u', $title)[0] ?? $title);
        $type = $this->pageType($slug);

        return [
            'name' => $title !== '' ? Str::limit($title, 60, '') : Str::headline(trim($slug, '/')),
            'source' => $slug,
            'type' => $type->value,
            'signature' => implode('>', $signature),
            'description' => 'The '.$slug.' page as the studio built it — '.count($blocks).' sections.',
            'sections' => $blocks,
        ];
    }

    /** The old site marked its pale and dark sections in the class attribute; the builder has presets. */
    private function background(string $classes): string
    {
        $padded = ' '.$classes.' ';

        if (preg_match('/\s[a-z0-9]+-dark\s/', $padded) === 1) {
            return 'navy';
        }

        if (preg_match('/\s[a-z0-9]+-soft\s/', $padded) === 1) {
            return 'light';
        }

        return '';
    }

    private function pageType(string $slug): PageType
    {
        return match (true) {
            $slug === '/' || $slug === '/home/' => PageType::Home,
            str_contains($slug, 'about') => PageType::About,
            str_contains($slug, 'contact') => PageType::Contact,
            str_contains($slug, 'book') => PageType::Booking,
            str_contains($slug, 'estimator') => PageType::Estimator,
            str_contains($slug, 'privacy') || str_contains($slug, 'terms') => PageType::Legal,
            str_contains($slug, 'insight') || str_contains($slug, 'blog') => PageType::Listing,
            str_contains($slug, 'cost') || str_contains($slug, 'guide') || str_contains($slug, 'price') => PageType::Insight,
            str_contains($slug, 'project') || str_contains($slug, 'portfolio') => PageType::Project,
            $this->isCity($slug) => PageType::City,
            default => PageType::Service,
        };
    }

    private function isCity(string $slug): bool
    {
        $city = str_replace(['-interior-design', '-design'], '', trim($slug, '/'));

        return in_array($city, [
            'lahore', 'karachi', 'islamabad', 'rawalpindi', 'sialkot', 'multan', 'faisalabad',
            'bahawalpur', 'gujranwala', 'peshawar', 'quetta', 'hyderabad', 'gujrat', 'sargodha',
        ], true) || str_starts_with(trim($slug, '/'), 'interior-design-');
    }

    /**
     * A short-array exporter: var_export writes `array ( 0 => … )`, which is not how the rest of the
     * seeders read.
     *
     * @param  array<array-key, mixed>  $value
     */
    private function export(array $value, int $depth): string
    {
        if ($value === []) {
            return '[]';
        }

        $pad = str_repeat('    ', $depth + 1);
        $lines = [];
        $list = array_is_list($value);

        foreach ($value as $key => $item) {
            $prefix = $list ? '' : var_export($key, true).' => ';
            $rendered = is_array($item) ? $this->export($item, $depth + 1) : var_export($item, true);
            $lines[] = $pad.$prefix.$rendered.',';
        }

        return "[\n".implode("\n", $lines)."\n".str_repeat('    ', $depth).']';
    }

    /**
     * @param  list<array{name: string, source: string, type: string, description: string, sections: list<array<string, mixed>>}>  $templates
     */
    private function php(array $templates): string
    {
        $rows = array_map(fn (array $template): array => [
            'name' => $template['name'],
            'source' => $template['source'],
            'page_type' => $template['type'],
            'description' => $template['description'],
            'sections' => $template['sections'],
        ], $templates);

        $export = $this->export($rows, 0);

        return <<<PHP
<?php

declare(strict_types=1);

/*
 * Page templates taken from the approved frontend (19 Oct).
 *
 * Generated by `php artisan woodex:frontend-templates --write` from platform/public/<slug>/index.html
 * with the same parser the legacy importer uses (App\\Domain\\Website\\Import\\LegacyHtml). Every layout
 * here is one the studio has already approved on the live site: the section order is the page's own,
 * the background rhythm comes from the page's own -soft / -dark markers, and the only copy kept is
 * each section's heading — a template never decides what a page says. Regenerate it after the
 * frontend changes; do not edit by hand.
 */

return {$export};

PHP;
    }
}
