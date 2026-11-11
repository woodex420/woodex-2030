<?php

declare(strict_types=1);

namespace App\Integrations\Mcp;

use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Models\Quote;
use App\Domain\Website\Builder\BuilderFile;
use App\Domain\Website\Models\Redirect;
use App\Domain\Website\Seo\FileAudit;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The read tools an AI client may call. One implementation for both transports: the JSON-RPC endpoint at
 * `/api/mcp` and the older REST bridge at `/api/bridge/v1`.
 *
 * The website is 142 files, so the bridge can finally read it: page list, one page, site health and the SEO
 * worklist. Nothing here writes, sends or publishes; every call is checked against the key's abilities *and*
 * the issuing user's live permissions.
 */
final class McpTools
{
    /** Tool definitions, in the order the client sees them. */
    public const array TOOLS = [
        'woodex_status' => ['ability' => 'status.read', 'description' => 'Confirm the bridge is reachable and list the abilities this key carries.'],
        'woodex_site_status' => ['ability' => 'site.read', 'description' => 'Live site health: page count, broken links, redirects to nowhere, images without alt text, SEO issues.'],
        'woodex_list_pages' => ['ability' => 'pages.read', 'description' => 'List the website pages that exist as files: path, URL, title, size, last modified.'],
        'woodex_get_page' => ['ability' => 'pages.read', 'description' => 'Read one page: title, meta description, headings, word count, images without alt text and SEO issues.'],
        'woodex_seo_report' => ['ability' => 'pages.read', 'description' => 'The SEO worklist: pages with findings, grouped by issue code, newest first.'],
        'woodex_list_leads' => ['ability' => 'leads.read', 'description' => 'Recent leads the issuing user may see, without contact details.'],
        'woodex_list_quotes' => ['ability' => 'quotes.read', 'description' => 'Recent quotes with status and integer totals, without client data.'],
    ];

    /** @return list<array<string, mixed>> */
    public static function definitions(): array
    {
        $schema = static fn (array $properties): array => ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];

        return [
            ['name' => 'woodex_status', 'description' => self::TOOLS['woodex_status']['description'], 'inputSchema' => $schema([])],
            ['name' => 'woodex_site_status', 'description' => self::TOOLS['woodex_site_status']['description'], 'inputSchema' => $schema([])],
            ['name' => 'woodex_list_pages', 'description' => self::TOOLS['woodex_list_pages']['description'], 'inputSchema' => $schema([
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
                'query' => ['type' => 'string', 'description' => 'Filter by text in the URL or title.'],
            ])],
            ['name' => 'woodex_get_page', 'description' => self::TOOLS['woodex_get_page']['description'], 'inputSchema' => $schema([
                'path' => ['type' => 'string', 'description' => 'Builder path, e.g. about/index.html'],
                'url' => ['type' => 'string', 'description' => 'Public URL, e.g. /about/'],
            ])],
            ['name' => 'woodex_seo_report', 'description' => self::TOOLS['woodex_seo_report']['description'], 'inputSchema' => $schema([
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 15],
            ])],
            ['name' => 'woodex_list_leads', 'description' => self::TOOLS['woodex_list_leads']['description'], 'inputSchema' => $schema([
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20],
                'after_id' => ['type' => 'integer', 'minimum' => 0],
            ])],
            ['name' => 'woodex_list_quotes', 'description' => self::TOOLS['woodex_list_quotes']['description'], 'inputSchema' => $schema([
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20],
                'after_id' => ['type' => 'integer', 'minimum' => 0],
            ])],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  list<string>  $abilities
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function call(string $tool, array $arguments, User $user, array $abilities = []): array
    {
        return match ($tool) {
            'woodex_status' => ['service' => 'Woodex website bridge', 'version' => '2.0.0', 'read_only' => true, 'abilities' => $abilities],
            'woodex_site_status' => $this->siteStatus(),
            'woodex_list_pages' => $this->listPages($arguments),
            'woodex_get_page' => $this->getPage($arguments),
            'woodex_seo_report' => $this->seoReport($arguments),
            'woodex_list_leads' => $this->leads($arguments, $user),
            'woodex_list_quotes' => $this->quotes($arguments, $user),
            default => throw ValidationException::withMessages(['tool' => 'There is no tool named '.$tool.'.']),
        };
    }

    /** @return array<string, mixed> */
    private function siteStatus(): array
    {
        $audit = (new FileAudit)->handle();

        return [
            'pages' => $audit['counts']['files'],
            'sitemap_urls' => $audit['counts']['files'],
            'flagged_pages' => $audit['counts']['flagged'],
            'seo_issues' => $audit['counts']['issues'],
            'broken_links' => $audit['counts']['broken_links'],
            'images_without_alt' => $audit['counts']['images_without_alt'],
            'orphan_pages' => $audit['counts']['orphans'],
            'redirects' => Redirect::query()->count(),
            'redirects_astray' => $audit['counts']['redirects_astray'],
            'site_findings' => $audit['site'],
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function listPages(array $arguments): array
    {
        $limit = max(1, min(200, (int) ($arguments['limit'] ?? 50)));
        $query = mb_strtolower(trim((string) ($arguments['query'] ?? '')));

        $pages = collect(BuilderFile::pages());

        if ($query !== '') {
            $pages = $pages->filter(fn (array $page): bool => str_contains(mb_strtolower($page['url'].' '.$page['title']), $query));
        }

        $total = $pages->count();

        return [
            'total' => $total,
            'returned' => min($limit, $total),
            'items' => $pages->take($limit)->map(fn (array $page): array => [
                'path' => $page['path'], 'url' => $page['url'], 'title' => $page['title'],
                'size' => $page['size'], 'modified_at' => date(DATE_ATOM, $page['mtime']),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function getPage(array $arguments): array
    {
        $path = trim((string) ($arguments['path'] ?? ''));
        $url = trim((string) ($arguments['url'] ?? ''));

        if ($path === '' && $url !== '') {
            $path = BuilderFile::pathForUrl($url);
        }

        if ($path === '' || ! BuilderFile::exists($path)) {
            throw ValidationException::withMessages(['path' => 'There is no page at that path or URL. Use woodex_list_pages first.']);
        }

        $html = BuilderFile::read($path);
        $audit = (new FileAudit)->handle();
        $entry = collect($audit['entries'])->firstWhere('path', $path);

        preg_match_all('~<h([12])\b[^>]*>(.*?)</h\1>~is', $html, $matches, PREG_SET_ORDER);
        $headings = ['h1' => [], 'h2' => []];

        foreach ($matches as $match) {
            $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5)) ?? '');

            if ($text !== '') {
                $headings['h'.(int) $match[1]][] = mb_substr($text, 0, 200);
            }
        }

        return [
            'path' => $path,
            'url' => BuilderFile::urlOf($path),
            'title' => $entry['title'] ?? '',
            'description' => $entry['description'] ?? '',
            'words' => $entry['words'] ?? 0,
            'headings' => $headings,
            'images_without_alt' => preg_match_all('~<img\b(?![^>]*\balt=)[^>]*>~i', $html),
            'issues' => $entry['issues'] ?? [],
            'modified_at' => date(DATE_ATOM, BuilderFile::mtime($path)),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function seoReport(array $arguments): array
    {
        $limit = max(1, min(50, (int) ($arguments['limit'] ?? 15)));
        $audit = (new FileAudit)->handle();
        $byCode = [];

        foreach ($audit['pages'] as $page) {
            foreach ($page['issues'] as $issue) {
                $byCode[$issue['code']] = ($byCode[$issue['code']] ?? 0) + 1;
            }
        }

        arsort($byCode);

        return [
            'pages' => $audit['counts']['files'],
            'flagged_pages' => $audit['counts']['flagged'],
            'open_issues' => $audit['counts']['issues'],
            'by_code' => (object) $byCode,
            'pages_with_findings' => collect($audit['pages'])->take($limit)->map(fn (array $page): array => [
                'path' => $page['path'], 'url' => $page['url'], 'title' => $page['title'],
                'issues' => array_column($page['issues'], 'code'),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function leads(array $arguments, User $user): array
    {
        Gate::authorize('viewAny', Lead::class);
        $limit = max(1, min(50, (int) ($arguments['limit'] ?? 20)));
        $after = max(0, (int) ($arguments['after_id'] ?? 0));

        $rows = Lead::query()->visibleTo($user)->with('stage:id,name')->where('id', '>', $after)
            ->orderBy('id')->limit($limit + 1)->get(['id', 'ref', 'service', 'city', 'stage_id', 'created_at']);
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return [
            'items' => $rows->map(fn (Lead $lead): array => [
                'id' => $lead->id, 'reference' => $lead->ref, 'service' => $lead->service,
                'city' => $lead->city, 'stage' => $lead->stage?->name, 'created_at' => $lead->created_at?->toIso8601String(),
            ])->values()->all(),
            'next_after_id' => $more ? $rows->last()?->id : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function quotes(array $arguments, User $user): array
    {
        Gate::authorize('viewAny', Quote::class);
        $limit = max(1, min(50, (int) ($arguments['limit'] ?? 20)));
        $after = max(0, (int) ($arguments['after_id'] ?? 0));

        $rows = Quote::query()->visibleTo($user)->where('id', '>', $after)->orderBy('id')
            ->limit($limit + 1)->get(['id', 'number', 'status', 'currency', 'total_minor']);
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return [
            'items' => $rows->map(fn (Quote $quote): array => [
                'id' => $quote->id, 'number' => $quote->number, 'status' => $quote->status->value,
                'currency' => $quote->currency, 'total_minor' => (int) $quote->total_minor,
            ])->values()->all(),
            'next_after_id' => $more ? $rows->last()?->id : null,
        ];
    }
}
