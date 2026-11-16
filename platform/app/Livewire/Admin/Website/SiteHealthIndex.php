<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Website;

use App\Domain\Website\Models\Media;
use App\Domain\Website\Models\Redirect;
use App\Domain\Website\Seo\FileAudit;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Site health: the website as a whole, read from the files the web server serves (Woodex OS 1, step 6).
 *
 * The screen used to audit the `pages` table — 56 rows for 143 pages — so it could report "healthy" about a
 * site that had 86 pages it had never opened. It now walks `public/`: internal links, orphan pages, missing
 * titles and descriptions, images without alt text, plus the redirects table and the two generated files.
 */
#[Layout('layouts::admin')]
#[Title('Site health')]
class SiteHealthIndex extends Component
{
    use InteractsWithAdmin;

    public function rescan(): void
    {
        $this->toast('Site scanned — '.$this->verdict());
    }

    public function render(): View
    {
        $audit = (new FileAudit)->handle();
        $site = collect($audit['site'])->keyBy('code');
        $meta = $this->pagesWithMetaIssues($audit);

        $findings = collect([
            [
                'code' => 'broken_links',
                'label' => 'Internal links that lead nowhere',
                'hint' => 'A visitor clicks, the page answers 404 — and the ranking that link earned dies with it. Every link in every file was checked against the files.',
                'ok' => ! $site->has('broken_links'),
                'message' => (string) ($site->get('broken_links')['message'] ?? 'Every internal link in every page resolves to a page that exists.'),
                'action' => null,
            ],
            [
                'code' => 'orphan_pages',
                'label' => 'Pages nothing links to',
                'hint' => 'No page links to them, so visitors and Google both arrive by luck. Link them from a page, or take them offline.',
                'ok' => ! $site->has('orphan_pages'),
                'message' => (string) ($site->get('orphan_pages')['message'] ?? 'Every page is linked from at least one other page.'),
                'action' => ['label' => 'Open All pages', 'route' => 'admin.website.pages'],
            ],
            [
                'code' => 'broken_redirects',
                'label' => 'Redirects pointing at no page',
                'hint' => 'An old URL is being sent to a page that does not exist. The Redirects screen has the fix.',
                'ok' => ! $site->has('broken_redirects'),
                'message' => (string) ($site->get('broken_redirects')['message'] ?? 'Every redirect lands on a real page.'),
                'action' => ['label' => 'Open Redirects', 'route' => 'admin.website.redirects'],
            ],
            [
                'code' => 'alt_text',
                'label' => 'Images without alt text',
                'hint' => 'Screen readers hear nothing, and Google neither. The brand mark carries alt="" on purpose; these are the content images in the page body.',
                'ok' => $audit['counts']['images_without_alt'] === 0,
                'message' => $audit['counts']['images_without_alt'] === 0
                    ? 'Every content image carries alt text.'
                    : sprintf('%d content image(s) have no alt text, across %d page(s).', $audit['counts']['images_without_alt'], $this->pagesWithAltIssues($audit)),
                'action' => ['label' => 'Open SEO agent', 'route' => 'admin.website.seo'],
            ],
            [
                'code' => 'meta',
                'label' => 'Titles and descriptions',
                'hint' => 'The blue link and the grey text in a search result: one per page, inside the cut-offs, no two pages the same.',
                'ok' => $meta === 0,
                'message' => $meta === 0
                    ? sprintf('All %d pages have a title and a description inside the cut-offs.', $audit['counts']['files'])
                    : sprintf('%d page(s) need a title or a description fixed.', $meta),
                'action' => ['label' => 'Open SEO', 'route' => 'admin.website.seo.overview'],
            ],
            [
                'code' => 'robots',
                'label' => 'robots.txt is generated',
                'hint' => 'The file that tells search engines what to crawl, served for the host that asks.',
                'ok' => Route::has('robots'),
                'message' => Route::has('robots')
                    ? 'The robots route is live at /robots.txt.'
                    : 'The robots route is missing — a deploy problem, not a content one.',
                'action' => null,
            ],
            [
                'code' => 'sitemap',
                'label' => 'sitemap.xml is generated',
                'hint' => 'The map search engines read first, built from the files themselves — so a page cannot be missing from it.',
                'ok' => Route::has('sitemap'),
                'message' => Route::has('sitemap')
                    ? sprintf('The sitemap route is live — %d page(s) from public/.', $audit['counts']['files'])
                    : 'The sitemap route is missing — a deploy problem, not a content one.',
                'action' => null,
            ],
        ]);

        return view('livewire.admin.website.site-health-index', [
            'findings' => $findings,
            'unhealthy' => $findings->where('ok', false)->count(),
            'stats' => [
                'files' => $audit['counts']['files'],
                'flagged' => $audit['counts']['flagged'],
                'redirects' => Redirect::query()->count(),
                'media' => Media::query()->count(),
            ],
        ]);
    }

    private function verdict(): string
    {
        $audit = (new FileAudit)->handle();

        return $audit['site'] === [] && $audit['counts']['images_without_alt'] === 0 && $audit['counts']['flagged'] === 0
            ? 'everything checks out'
            : $audit['counts']['flagged'].' page(s) with findings';
    }

    /**
     * Pages whose findings are about the search listing itself, not an image.
     *
     * @param  array{entries: list<array<string, mixed>>, pages: list<array<string, mixed>>, site: list<array<string, mixed>>, counts: array<string, int>}  $audit
     */
    private function pagesWithMetaIssues(array $audit): int
    {
        $codes = [
            'missing_title', 'long_title', 'short_title', 'duplicate_title',
            'missing_description', 'long_description', 'duplicate_description',
            'missing_h1', 'multiple_h1', 'thin_content',
        ];

        return $this->countPagesWith($audit, $codes);
    }

    /**
     * @param  array{entries: list<array<string, mixed>>, pages: list<array<string, mixed>>, site: list<array<string, mixed>>, counts: array<string, int>}  $audit
     */
    private function pagesWithAltIssues(array $audit): int
    {
        return $this->countPagesWith($audit, ['missing_alt']);
    }

    /**
     * @param  array{entries: list<array<string, mixed>>, pages: list<array<string, mixed>>, site: list<array<string, mixed>>, counts: array<string, int>}  $audit
     * @param  list<string>  $codes
     */
    private function countPagesWith(array $audit, array $codes): int
    {
        $count = 0;

        foreach ($audit['pages'] as $page) {
            foreach ((array) $page['issues'] as $issue) {
                if (in_array($issue['code'] ?? '', $codes, true)) {
                    $count++;

                    break;
                }
            }
        }

        return $count;
    }
}
