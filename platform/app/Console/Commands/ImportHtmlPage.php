<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Website\Enums\PageStatus;
use App\Domain\Website\Enums\PageType;
use App\Domain\Website\Models\Page;
use App\Domain\Website\Services\Menus;
use App\Domain\Website\Services\PageCache;
use Illuminate\Console\Command;

/**
 * Turns an exported v1 page into an HTML CMS page the Visual Page Builder can edit.
 *
 * The markup is stored byte for byte — the design is final, so the import never rewrites it. The CMS
 * row keeps the same slug, so publishing the imported page is the Phase 3 cutover for that URL, and
 * the static file keeps answering until the rewrite changes.
 */
final class ImportHtmlPage extends Command
{
    protected $signature = 'website:import-html
        {slug=/ : The page address, e.g. / or /about/}
        {--file= : File inside that folder (default index.html)}
        {--dry-run : Report what would be imported without writing}';

    protected $description = 'Import a v1 static page as an HTML page for the Visual Page Builder.';

    public function handle(): int
    {
        $slug = Page::normalizeSlug((string) $this->argument('slug'));
        $folder = trim($slug, '/');
        $file = (string) ($this->option('file') ?: 'index.html');
        $path = public_path(($folder !== '' ? $folder.'/' : '').$file);

        if (! is_file($path)) {
            $this->error('No such file: '.$path);

            return self::FAILURE;
        }

        $html = (string) file_get_contents($path);
        $page = Page::query()->firstOrNew(['slug' => $slug]);
        $title = $page->title ?: $this->titleOf($html, $slug);

        $this->table(['', ''], [
            ['Page', $slug],
            ['Source', $path],
            ['Bytes', number_format(strlen($html))],
            ['Title', $title],
            ['Existing page', $page->exists ? 'yes (#'.$page->id.', '.($page->template ?: 'blocks').')' : 'no — created'],
        ]);

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing written.');

            return self::SUCCESS;
        }

        $page->fill([
            'type' => $page->type ?? ($slug === '/' ? PageType::Home : PageType::Page),
            'title' => $title,
            'status' => $page->status ?? PageStatus::Published,
            'published_at' => $page->published_at ?? now(),
            'template' => 'html',
            'canvas_html' => $html,
        ]);

        if (($page->seo['description'] ?? '') === '') {
            $page->seo = array_merge((array) $page->seo, ['description' => $this->descriptionOf($html)]);
        }

        $page->save();

        PageCache::invalidate($page);
        Menus::flush();

        $this->info('Imported '.$slug.' — open it at /admin/website/builder/'.$page->id);

        return self::SUCCESS;
    }

    private function titleOf(string $html, string $slug): string
    {
        if (preg_match('~<title>(.*?)</title>~is', $html, $match) === 1) {
            $title = trim(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5));

            if ($title !== '') {
                return mb_substr($title, 0, 190);
            }
        }

        return $slug === '/' ? 'Home' : mb_substr(ucwords(str_replace(['-', '/'], ' ', trim($slug, '/'))), 0, 190);
    }

    private function descriptionOf(string $html): string
    {
        if (preg_match('~<meta\s+name=["\']description["\']\s+content=["\'](.*?)["\']~is', $html, $match) === 1) {
            return mb_substr(trim(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5)), 0, 320);
        }

        return '';
    }
}
