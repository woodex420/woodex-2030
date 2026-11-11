<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Website\Models\Page;
use Illuminate\Console\Command;

/**
 * Where the v1 → v2 conversion stands (docs/13): the switch is file existence — while a group's
 * files remain in public/, the files win and the CMS page waits. This command walks the eight
 * groups in the plan's order and reports, per group, how many pages still have their v1 file, how
 * many CMS pages are ready (published), and therefore how far the group is from switching. The
 * actual switch — deleting a group's files — happens on the server, after the owner's Go.
 */
class ConversionStatus extends Command
{
    protected $signature = 'woodex:conversion-status {--files-root= : Where the v1 files live (defaults to public/)}';

    protected $description = 'Per-group state of the v1 → v2 conversion (docs/13): which URLs the files still serve, which the CMS does.';

    public function handle(): int
    {
        $root = rtrim((string) ($this->option('files-root') ?: public_path()), '/');
        $pages = Page::query()->published()->get(['id', 'slug', 'title', 'type']);
        $fileServed = 0;
        $cmsServed = 0;
        $rows = [];

        foreach ($this->groups() as $group) {
            $groupPages = $pages->filter(fn (Page $page): bool => $group['match']((string) $page->slug, $page));

            $files = $groupPages->filter(fn (Page $page): bool => is_file($root.$this->path((string) $page->slug)))->count();
            $fileServed += $files;
            $cmsServed += $groupPages->count() - $files;

            $rows[] = [
                $group['name'],
                (string) $groupPages->count(),
                (string) $files,
                (string) ($groupPages->count() - $files),
                $files === 0 && $groupPages->count() > 0 ? 'CMS serves this group' : ($files === $groupPages->count() ? 'files still serve' : 'partly switched'),
            ];
        }

        $this->table(['Group (docs/13 order)', 'Published pages', 'v1 files still there', 'CMS-served', 'State'], $rows);
        $this->line(sprintf('%d of %d published pages are still served by v1 files; %d by the CMS.', $fileServed, $fileServed + $cmsServed, $cmsServed));
        $this->line('A group switches only after the parity tool (tools/website/compare.php) is green and the owner says go (docs/13 §6).');

        return self::SUCCESS;
    }

    /**
     * The eight groups, in the plan's order. Match closures run against the slug (and page for type).
     *
     * @return list<array{name: string, match: callable(string, Page): bool}>
     */
    private function groups(): array
    {
        $exact = static fn (array $slugs): callable => static fn (string $slug): bool => in_array(rtrim($slug, '/') ?: '/', $slugs, true);

        $rooms = ['kitchen', 'bathroom', 'bedroom', 'living', 'lounge', 'dining', 'washroom', 'wardrobe', 'tv', 'balcony', 'stairs', 'foyer', 'pooja'];

        return [
            ['name' => '1 · Legal & utility', 'match' => $exact(['/privacy', '/contact', '/estimator', '/book-a-visit'])],
            ['name' => '2 · Insights', 'match' => static fn (string $slug): bool => str_starts_with($slug, '/insights')],
            ['name' => '3 · Cities & areas', 'match' => static fn (string $slug, Page $page): bool => in_array($page->type->value, ['city', 'area'], true)],
            ['name' => '4 · Projects', 'match' => static fn (string $slug, Page $page): bool => $page->type->value === 'project' || rtrim($slug, '/') === '/projects'],
            ['name' => '5 · Rooms & property sizes', 'match' => static function (string $slug) use ($rooms): bool {
                $stem = basename(rtrim($slug, '/'));

                return str_contains($stem, 'marla') || str_contains($stem, 'kanal')
                    || collect($rooms)->contains(fn (string $room): bool => str_contains($stem, $room));
            }],
            ['name' => '6 · Services & specialists', 'match' => static fn (string $slug, Page $page): bool => $page->type->value === 'service'],
            ['name' => '7 · Hubs (about, services)', 'match' => $exact(['/about', '/services'])],
            ['name' => '8 · Home', 'match' => $exact(['/'])],
        ];
    }

    private function path(string $slug): string
    {
        $slug = trim($slug, '/');

        return $slug === '' ? '/index.html' : '/'.$slug.'/index.html';
    }
}
