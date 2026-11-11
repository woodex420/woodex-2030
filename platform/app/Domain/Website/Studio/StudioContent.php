<?php

declare(strict_types=1);

namespace App\Domain\Website\Studio;

use App\Domain\Website\Builder\ArticleFile;
use App\Domain\Website\Enums\PageStatus;
use App\Domain\Website\Enums\PageType;
use App\Domain\Website\Models\Media;
use App\Domain\Website\Models\Page;
use App\Domain\Website\Models\PageBlock;
use App\Domain\Website\Models\PageVersion;
use App\Domain\Website\Services\ListingIndex;
use App\Domain\Website\Services\Menus;
use App\Domain\Website\Services\PageCache;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The article and study editor behind the ported v2.1 admin (docs/audit/16.8).
 *
 * v2.1 keeps a post in its own table and writes a static HTML file on publish. Woodex OS keeps a post as a
 * page, and the site renders pages from blocks — so this class is the translation, in both directions:
 *
 *   editor `data`  →  one `text` block whose `studio` key holds the editor's own JSON, plus the columns the
 *                     rest of the OS already understands (title, slug, excerpt, seo, featured image)
 *   page           ←  the same JSON, so the editor reopens on exactly what it saved
 *
 * Nothing is guessed on the way back: an article written before the port (48 of them) is read out of its
 * hero/text/intro blocks, so it opens with its real words instead of an empty form.
 *
 * Publishing writes the page file (`ArticleFile`): the site is the files, so a post that is only a row answers
 * 404. The design's own page is used as the shell, and only its head metadata and `<main>` are replaced. A copy
 * of the blocks is kept as a `PageVersion` before every save, which is the rollback the static backup used to give.
 */
final class StudioContent
{
    /** The two content types the editor opens, and the page type each one is. */
    private const array TYPES = [
        'post' => ['type' => PageType::Insight, 'folder' => '/insights/'],
        'study' => ['type' => PageType::Project, 'folder' => '/projects/'],
    ];

    public function __construct(private readonly User $user) {}

    /**
     * `cms_list` — the rows the list screen draws.
     *
     * @return array<string, mixed>
     */
    public function listing(string $type, string $search = ''): array
    {
        $definition = $this->definition($type);

        $pages = Page::query()
            ->where('type', $definition['type'])
            ->when(trim($search) !== '', function ($query) use ($search): void {
                $needle = '%'.Str::lower(trim($search)).'%';
                $query->where(function ($inner) use ($needle): void {
                    $inner->whereRaw('lower(title) like ?', [$needle])->orWhereRaw('lower(slug) like ?', [$needle]);
                });
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        return [
            'items' => $pages->map(function (Page $page) use ($definition): array {
                $data = $this->payload($page);

                return [
                    'id' => $page->id,
                    'type' => array_search($definition, self::TYPES, true) ?: 'post',
                    'title' => (string) $page->title,
                    'slug' => $this->shortSlug($page),
                    'status' => $page->status === PageStatus::Published ? 'published' : 'draft',
                    'updated_at' => $page->updated_at?->toIso8601String(),
                    'updated_by' => '',
                    'rel' => $this->relFor($page, array_search($definition, self::TYPES, true) ?: 'post'),
                    'data' => [
                        'kicker' => $data['kicker'] ?? '',
                        'card' => $data['card'] ?? [],
                    ],
                ];
            })->values()->all(),
            'aiReady' => false,
        ];
    }

    /**
     * `cms_get` — one item, in the shape the editor form reads.
     *
     * @return array<string, mixed>
     */
    public function get(int $id, string $type): array
    {
        $definition = $this->definition($type);
        $page = Page::query()->where('type', $definition['type'])->find($id);

        if ($page === null) {
            throw new RuntimeException('That item is not in the list.');
        }

        return ['item' => $this->item($page, $type)];
    }

    /**
     * `cms_save` — create or update. Returns the item the way `cms_get` would, so the editor can adopt the
     * new id without a reload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function save(array $payload): array
    {
        $type = (string) ($payload['type'] ?? 'post');
        $definition = $this->definition($type);

        $title = trim((string) ($payload['title'] ?? ''));
        $slug = trim((string) ($payload['slug'] ?? ''));

        if ($title === '') {
            throw new RuntimeException('Add a title.');
        }

        if (preg_match('/^[a-z0-9][a-z0-9-]{0,59}$/', $slug) !== 1) {
            throw new RuntimeException('Page address: lowercase letters, numbers and dashes.');
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $seo = is_array($payload['seo'] ?? null) ? $payload['seo'] : [];
        $id = (int) ($payload['id'] ?? 0);

        $path = $definition['folder'].$slug.'/';

        $clash = Page::withTrashed()->where('slug', $path)->when($id > 0, fn ($query) => $query->whereKeyNot($id))->first();

        if ($clash !== null) {
            throw new RuntimeException('Another page already uses that address.');
        }

        $heroId = $this->mediaId((string) ($data['hero']['src'] ?? ''));
        $listing = Page::query()->where('slug', rtrim($definition['folder'], '/').'/')->first();

        $page = DB::transaction(function () use ($id, $type, $definition, $title, $path, $data, $seo, $heroId, $listing): Page {
            $page = $id > 0
                ? Page::query()->where('type', $definition['type'])->find($id)
                : new Page;

            if ($page === null) {
                throw new RuntimeException('That item is not in the list.');
            }

            $creating = ! $page->exists;

            // The blocks the page has now become a version first: saving from the editor is a full replace,
            // and the old static site's promise was that nothing is lost. This keeps that promise.
            if (! $creating) {
                PageVersion::query()->create([
                    'page_id' => $page->id,
                    'user_id' => $this->user->id,
                    'data' => [
                        'title' => $page->title,
                        'seo' => $page->seo,
                        'blocks' => $page->blocks()->orderBy('position')->get()->map(fn (PageBlock $block): array => [
                            'type' => $block->type->value,
                            'block_key' => $block->block_key,
                            'position' => $block->position,
                            'visible' => $block->visible,
                            'data' => $block->data,
                            'settings' => $block->settings,
                        ])->all(),
                    ],
                ]);
            }

            // A renamed post must not keep answering at its old address: the old file goes to storage.
            if (! $creating && (string) $page->slug !== $path) {
                ArticleFile::retire($page, $type);
            }

            $page->fill([
                'type' => $definition['type'],
                'slug' => $path,
                'title' => $title,
                'excerpt' => trim((string) ($data['dek'] ?? '')) ?: null,
                'status' => $creating ? PageStatus::Draft : $page->status,
                'parent_id' => $page->parent_id ?? $listing?->id,
                'author_id' => $page->author_id ?? $this->user->id,
                'featured_image_id' => $heroId,
                'seo' => array_filter([
                    'title' => trim((string) ($seo['title'] ?? '')) ?: $title,
                    'description' => trim((string) ($seo['description'] ?? '')) ?: (trim((string) ($data['dek'] ?? '')) ?: null),
                    'robots' => 'index,follow',
                    'focus_keyword' => trim((string) ($seo['kw'] ?? '')) ?: null,
                    'og_image_id' => $this->mediaId((string) ($seo['og'] ?? '')) ?? $heroId,
                ], fn ($value): bool => $value !== null),
            ])->save();

            // One section holds the article as the editor wrote it; the public renderer reads the same JSON.
            $page->blocks()->where('type', 'text')->where('block_key', 'like', 'bstu%')->delete();

            $page->blocks()->create([
                'type' => 'text',
                'block_key' => 'bstu'.Str::lower(Str::random(8)),
                'data' => ['studio' => $data, 'hero_id' => $heroId],
                'visible' => true,
                'position' => 0,
            ]);

            return $page;
        });

        PageCache::invalidate($page);
        Menus::flush();

        // A published article is its file: saving one writes what the editor holds into the page.
        if ($page->status === PageStatus::Published) {
            ArticleFile::publish($page, $type);
        }

        return ['item' => $this->item($page->refresh(), $type)];
    }

    /**
     * `cms_status` — the small Live switch in the list.
     *
     * @return array<string, mixed>
     */
    public function setStatus(int $id, string $status, string $type): array
    {
        $definition = $this->definition($type);
        $page = Page::query()->where('type', $definition['type'])->find($id);

        if ($page === null) {
            throw new RuntimeException('That item is not in the list.');
        }

        $wanted = $status === 'published' ? PageStatus::Published : PageStatus::Draft;

        if ($wanted === PageStatus::Published) {
            abort_unless($this->user->can('website.publish'), 403);
            $page->forceFill(['status' => PageStatus::Published, 'published_at' => $page->published_at ?? now()]);
        } else {
            $page->forceFill(['status' => PageStatus::Draft]);
            ListingIndex::remove($page);
        }

        $page->save();

        // Live means the URL answers, draft means it does not — and the URL is the file.
        if ($wanted === PageStatus::Published) {
            ArticleFile::publish($page, $type);
        } else {
            ArticleFile::retire($page, $type);
        }

        PageCache::invalidate($page);
        Menus::flush();

        return ['item' => $this->item($page->refresh(), $type)];
    }

    /**
     * `cms_delete` — a draft goes, a live page is refused (the OS's own rule).
     *
     * @return array<string, mixed>
     */
    public function delete(int $id, string $type): array
    {
        $definition = $this->definition($type);
        $page = Page::query()->where('type', $definition['type'])->find($id);

        if ($page === null) {
            throw new RuntimeException('That item is not in the list.');
        }

        if ($page->status === PageStatus::Published) {
            throw new RuntimeException('This page is live. Take it off the site first, then delete it.');
        }

        ArticleFile::retire($page, $type);

        PageCache::invalidate($page);
        $page->delete();

        return [];
    }

    /**
     * `cms_published` — the publish button. It writes the page file, which is what the URL serves.
     *
     * @return array<string, mixed>
     */
    public function publish(int $id, string $type): array
    {
        abort_unless($this->user->can('website.publish'), 403);

        $definition = $this->definition($type);
        $page = Page::query()->where('type', $definition['type'])->find($id);

        if ($page === null) {
            throw new RuntimeException('That item is not in the list.');
        }

        // The file is the live result, so it is written first: a row flipped to Live before a write that fails
        // leaves a "Live" badge over a 404, which is the one thing this admin must never show (step 19). A page
        // whose file cannot be written stays whatever it was, and the editor is told why.
        $path = ArticleFile::publish($page, $type);

        if ($path === null) {
            throw new RuntimeException('This item has no page to write — give it a page address first.');
        }

        $page->forceFill(['status' => PageStatus::Published, 'published_at' => $page->published_at ?? now()])->save();

        // The index pages (/insights/, /projects/) carry a hand-curated card list; a published article that
        // never appears there is invisible. The editor does not know about that list, so the server adds it.
        ListingIndex::add($page);

        PageCache::invalidate($page);
        Menus::flush();

        return ['item' => $this->item($page->refresh(), $type)];
    }

    /**
     * @return array{type: PageType, folder: string}
     */
    private function definition(string $type): array
    {
        return self::TYPES[$type] ?? throw new RuntimeException('That content type is not ported yet.');
    }

    /** The page's file, when it is on disk — null for a draft or a page whose file is missing. */
    private function relFor(Page $page, string $type): ?string
    {
        $path = ArticleFile::pathFor($page, $type);

        return $path !== null && is_file(ArticleFile::root().'/'.$path) ? $path : null;
    }

    /**
     * The page, as the editor expects it.
     *
     * @return array<string, mixed>
     */
    private function item(Page $page, string $type): array
    {
        return [
            'id' => $page->id,
            'type' => $type,
            'title' => (string) $page->title,
            'slug' => $this->shortSlug($page),
            'status' => $page->status === PageStatus::Published ? 'published' : 'draft',
            'data' => $this->payload($page),
            // The file the page is. The editor reads a non-empty `rel` as "this is live": it then offers
            // Publish changes and View live instead of Publish now and Delete draft, and locks the address.
            // It used to be null here, so a just-published article still looked like a draft (step 19).
            'rel' => $this->relFor($page, $type),
            'seo' => [
                'title' => (string) ($page->seo['title'] ?? ''),
                'description' => (string) ($page->seo['description'] ?? ''),
                'kw' => (string) ($page->seo['focus_keyword'] ?? ''),
                'og' => isset($page->seo['og_image_id']) ? $this->urlFor((int) $page->seo['og_image_id']) : '',
            ],
        ];
    }

    /**
     * The article payload: the studio's own JSON when the page was written in the editor, otherwise read
     * out of the blocks the imported pages already have — so all 48 articles open with their real words.
     *
     * @return array<string, mixed>
     */
    private function payload(Page $page): array
    {
        $studio = $page->blocks()->where('type', 'text')->where('block_key', 'like', 'bstu%')->first();

        if ($studio !== null && is_array($studio->data['studio'] ?? null)) {
            return $studio->data['studio'];
        }

        $hero = $page->blocks()->where('type', 'hero')->orderBy('position')->first();
        $facts = $page->blocks()->where('type', 'intro')->orderBy('position')->first();
        $blocks = [];

        foreach ($page->blocks()->orderBy('position')->get() as $block) {
            if (in_array($block->type->value, ['hero', 'intro', 'form', 'faq', 'related'], true)) {
                continue;
            }

            $heading = trim((string) ($block->data['heading'] ?? ''));
            $body = trim((string) ($block->data['body'] ?? $block->data['text'] ?? ''));

            if ($heading !== '') {
                $blocks[] = ['t' => 'h', 'text' => $heading];
            }

            foreach (preg_split('/\n\s*\n/', $body) ?: [] as $paragraph) {
                if (trim($paragraph) !== '') {
                    $blocks[] = ['t' => 'p', 'text' => trim($paragraph)];
                }
            }
        }

        $meta = [];

        foreach ((array) ($facts?->data['facts'] ?? []) as $fact) {
            if (is_array($fact) && ($fact['label'] ?? '') !== '') {
                $meta[] = ['k' => (string) $fact['label'], 'v' => (string) ($fact['value'] ?? '')];
            }
        }

        $heroId = (int) ($hero?->data['image_id'] ?? 0);
        $heroSrc = $heroId > 0 ? $this->urlFor($heroId) : '';
        $featured = (int) $page->featured_image_id > 0 ? $this->urlFor((int) $page->featured_image_id) : '';

        return [
            'kicker' => (string) ($hero?->data['eyebrow'] ?? ''),
            'dek' => (string) ($page->excerpt ?? $hero?->data['text'] ?? ''),
            // The hero block keeps the artwork of every imported article; the alt is the one in the library, so
            // the editor shows (and the page keeps) the words the Media screen holds.
            'hero' => ['src' => $heroSrc, 'alt' => $heroId > 0 ? (string) Media::query()->whereKey($heroId)->value('alt') : ''],
            'meta' => $meta,
            'blocks' => $blocks,
            'summary' => [],
            'faqs' => [],
            'quote' => '',
            'related' => [],
            // The listing card falls back to the hero: an imported article has no separate card image, and an
            // empty grey square on every row in the list is not a picture of anything.
            'card' => ['src' => $featured !== '' ? $featured : $heroSrc],
        ];
    }

    private function shortSlug(Page $page): string
    {
        return trim((string) preg_replace('#^/(insights|projects)/#', '', (string) $page->slug), '/');
    }

    private function urlFor(int $mediaId): string
    {
        return Media::query()->find($mediaId)?->url() ?? '';
    }

    private function mediaId(string $url): ?int
    {
        return preg_match('~/media/(\d+)/~', $url, $matches) === 1 ? (int) $matches[1] : null;
    }
}
