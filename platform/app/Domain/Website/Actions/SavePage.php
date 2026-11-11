<?php

declare(strict_types=1);

namespace App\Domain\Website\Actions;

use App\Domain\Website\Enums\BlockType;
use App\Domain\Website\Enums\PageStatus;
use App\Domain\Website\Models\Page;
use App\Domain\Website\Models\PageBlock;
use App\Domain\Website\Models\PageVersion;
use App\Domain\Website\Services\Menus;
use App\Domain\Website\Services\PageCache;
use App\Domain\Website\Support\CanvasHtml;
use App\Domain\Website\Support\Columns;
use App\Domain\Website\Support\SectionStyle;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes a page and its sections (docs/09 §2.2). Every publish takes a snapshot first, so a page can be put back
 * exactly as it was, and drops the cached copy of the page so the change is live at once.
 */
final class SavePage
{
    /**
     * @param  array<string, mixed>  $input
     * @param  list<array<string, mixed>>  $blocks
     */
    public function __invoke(Page $page, array $input, array $blocks, bool $publish, ?User $user): Page
    {
        // Free HTML (the HTML block and the campaign canvas) is the only staff input that reaches a
        // visitor as markup. It is owner-only: the permission is the guard, the editor's hidden
        // button is only the courtesy (Phase 1, finding S-03).
        if (! $user?->can('website.raw_html')) {
            $htmlBlock = collect($blocks)->contains(
                static fn (array $block): bool => (string) ($block['type'] ?? '') === BlockType::Html->value,
            );

            if ($htmlBlock || ($input['template'] ?? null) === 'canvas') {
                throw new AuthorizationException('Only the owner can save pages with free HTML.');
            }
        }

        DB::transaction(function () use ($page, $input, $blocks, $publish, $user): void {
            $page->fill([
                'type' => $input['type'],
                'slug' => Page::normalizeSlug((string) $input['slug']),
                'title' => $input['title'],
                'excerpt' => $input['excerpt'] ?: null,
                'seo' => $input['seo'],
                'schema_type' => $input['schema_type'] ?: $input['type']->schemaType(),
                'author_id' => $page->author_id ?? $user?->id,
                'sort' => (int) ($input['sort'] ?? 0),
                // The campaign canvas (D-107): hand-placed HTML instead of sections, scripts
                // stripped at the door. The sections underneath stay — switching back loses nothing.
                'template' => ($input['template'] ?? null) === 'canvas' ? 'canvas' : null,
                'canvas_html' => ($input['template'] ?? null) === 'canvas' && trim((string) ($input['canvas_html'] ?? '')) !== ''
                    ? CanvasHtml::clean((string) $input['canvas_html'])
                    : null,
            ]);

            if ($publish) {
                $page->status = PageStatus::Published;
                $page->published_at ??= now();
            }

            $page->save();

            $this->syncBlocks($page, $blocks);

            if ($publish) {
                $this->snapshot($page->fresh(['blocks']), $user);
            }

            PageCache::invalidate($page);
            Menus::flush(); // a title or a URL change must reach the menus and the sitemap
        });

        return $page->fresh(['blocks']);
    }

    /** @param  list<array<string, mixed>>  $blocks */
    private function syncBlocks(Page $page, array $blocks): void
    {
        $keep = [];

        foreach (array_values($blocks) as $position => $block) {
            $type = BlockType::from((string) $block['type']);
            $data = $this->clean($type, (array) ($block['data'] ?? []));
            $visible = (bool) ($block['visible'] ?? true);
            $key = $this->blockKey($block);

            /** @var PageBlock $model */
            $model = PageBlock::query()->updateOrCreate(
                ['id' => $block['id'] ?? null, 'page_id' => $page->id],
                ['type' => $type, 'block_key' => $key, 'data' => $data, 'settings' => SectionStyle::clean((array) ($block['settings'] ?? [])), 'visible' => $visible, 'position' => $position],
            );

            $keep[] = $model->id;
        }

        $page->blocks()->whereKeyNot($keep)->delete();
    }

    /**
     * The section's stable key (V2-3 click-to-edit): kept when the editor sends one, minted when it
     * does not — a restore rebuilds blocks without keys, and those must still become clickable.
     *
     * @param  array<string, mixed>  $block
     */
    private function blockKey(array $block): string
    {
        $key = (string) ($block['key'] ?? '');

        return $key !== '' ? $key : 'b'.Str::lower(Str::random(8));
    }

    /**
     * Numbers stay numbers, empty strings stay empty, and only keys the section knows about are stored — so a
     * section can be renamed or reordered without leaving junk behind.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clean(BlockType $type, array $data): array
    {
        // The columns section carries its own children: one JSON value, three leaf kinds, 2–4 of them.
        if ($type === BlockType::Columns) {
            return Columns::clean($data);
        }

        $clean = [];

        foreach ($type->fields() as $key => $default) {
            $value = $data[$key] ?? $default;

            // The HTML block is the one section whose text is markup: it is cleaned here (and again
            // when it renders), so a pasted <script> never reaches the database in the first place.
            if ($type === BlockType::Html && $key === 'code') {
                $clean[$key] = CanvasHtml::clean(is_string($value) ? $value : (string) $value);

                continue;
            }

            if (is_string($value) && $key !== 'body') {
                $value = trim($value);
            }

            $clean[$key] = match (true) {
                is_bool($default) => (bool) $value,
                is_array($default) => is_array($value) ? $this->rows($value) : [],
                is_int($default) || is_numeric($value) => $this->number($value),
                default => (string) $value,
            };
        }

        return $clean;
    }

    /**
     * Rows of a repeating field, without the empty ones.
     *
     * @param  array<int|string, mixed>  $rows
     * @return list<mixed>
     */
    private function rows(array $rows): array
    {
        return array_values(array_filter($rows, fn (mixed $row): bool => $row !== null && $row !== []));
    }

    /** Image ids and counts: empty stays empty, so "no image" is null rather than 0. */
    private function number(mixed $value): ?int
    {
        return $value === '' || $value === null ? null : (int) $value;
    }

    /** A snapshot of the page as it is published now: one click puts it back (docs/09 §2.1). */
    private function snapshot(Page $page, ?User $user): void
    {
        PageVersion::query()->create([
            'page_id' => $page->id,
            'user_id' => $user?->id,
            'data' => [
                'title' => $page->title,
                'excerpt' => $page->excerpt,
                'seo' => $page->seo,
                'blocks' => $page->blocks->map(fn (PageBlock $block): array => [
                    'type' => $block->type->value,
                    'key' => $block->block_key,
                    'data' => $block->data,
                    'settings' => $block->settings,
                    'visible' => $block->visible,
                ])->all(),
            ],
        ]);
    }
}
