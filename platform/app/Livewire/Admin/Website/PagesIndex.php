<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Website;

use App\Domain\Website\Builder\BuilderFile;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Every page of the public website — as a file (Woodex OS 1, docs/audit/16.19 step 6).
 *
 * The site is the v1 files under `public/`: the web server answers them before the application is asked. So
 * this list is the builder's own page list (`BuilderFile::pages()`), not the `pages` table: a row cannot put a
 * word on a live page, and showing rows here is what made the screen claim a result it did not have. Opening a
 * page hands it to the builder, the one editor that writes the file.
 *
 * Retiring a page moves its file to `storage/app/private/site-retired/…` — it is reversible, and it is the only
 * way to take a page off the site, because the file *is* the page.
 */
#[Layout('layouts::admin')]
#[Title('Pages')]
final class PagesIndex extends Component
{
    use InteractsWithAdmin;

    public string $search = '';

    /** The first segment of the URL: home, insights, projects, lahore… */
    public string $section = '';

    public function mount(): void
    {
        abort_unless($this->user()->can('website.edit'), 403);
    }

    /**
     * Take a page off the site. The file is kept — moved to storage with a version snapshot first — so this is a
     * reversible action, not a delete.
     */
    public function retire(string $path): void
    {
        abort_unless($this->user()->can('website.publish'), 403);

        $title = BuilderFile::titleOf(BuilderFile::read($path));

        BuilderFile::retire($path, $this->user());

        $this->toast('“'.$title.'” is off the site. Its file is kept in storage, under site-retired.');
    }

    public function render(): View
    {
        $all = collect(BuilderFile::pages())
            ->map(static fn (array $page): array => $page + ['section' => self::sectionOf($page['url'])]);

        $pages = $all
            ->when($this->section !== '', fn ($pages) => $pages->where('section', $this->section))
            ->when($this->search !== '', fn ($pages) => $pages->filter(fn (array $page): bool => str_contains(
                mb_strtolower($page['title'].' '.$page['url'].' '.$page['path']),
                mb_strtolower(trim($this->search)),
            )))
            ->values();

        return view('livewire.admin.website.pages-index', [
            'pages' => $pages,
            'sections' => $all->pluck('section')->unique()->sort()->values()->all(),
            'counts' => [
                'pages' => $all->count(),
                'sections' => $all->pluck('section')->unique()->count(),
                // What changed lately is the question a page list is opened to answer.
                'recent' => $all->filter(fn (array $page): bool => $page['mtime'] >= now()->subDays(7)->getTimestamp())->count(),
                'media' => DB::table('website_media')->count(),
            ],
        ]);
    }

    /**
     * `/insights/a-post/` → `insights`, `/about/` → `top-level`, `/` → `home`.
     *
     * The first segment only groups pages that sit under one: a service page is its own folder, so grouping by
     * the first segment would give 81 "sections" of one page each — a filter that filters nothing.
     */
    private static function sectionOf(string $url): string
    {
        $segments = array_values(array_filter(explode('/', trim($url, '/')), static fn (string $s): bool => $s !== ''));

        return match (true) {
            $segments === [] => 'home',
            count($segments) === 1 => 'top-level',
            default => $segments[0],
        };
    }
}
