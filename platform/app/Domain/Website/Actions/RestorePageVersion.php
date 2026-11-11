<?php

declare(strict_types=1);

namespace App\Domain\Website\Actions;

use App\Domain\Website\Enums\BlockType;
use App\Domain\Website\Models\Page;
use App\Domain\Website\Models\PageBlock;
use App\Domain\Website\Models\PageVersion;
use App\Domain\Website\Services\Menus;
use App\Domain\Website\Services\PageCache;
use App\Domain\Website\Support\SectionStyle;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Puts a page back exactly as an earlier version was (docs/09 §2.1). The current state is not lost: restoring
 * writes a new version on top of the stack, so a restore can be undone.
 */
final class RestorePageVersion
{
    public function __invoke(PageVersion $version, User $user): Page
    {
        $page = $version->page;

        DB::transaction(function () use ($page, $version, $user): void {
            $data = $version->data;

            $page->fill([
                'title' => $data['title'] ?? $page->title,
                'excerpt' => $data['excerpt'] ?? null,
                'seo' => $data['seo'] ?? null,
            ])->save();

            $page->blocks()->delete();

            foreach (array_values((array) ($data['blocks'] ?? [])) as $position => $block) {
                PageBlock::query()->create([
                    'page_id' => $page->id,
                    'type' => BlockType::from((string) $block['type']),
                    'data' => $block['data'] ?? [],
                    'settings' => SectionStyle::clean((array) ($block['settings'] ?? [])),
                    'visible' => (bool) ($block['visible'] ?? true),
                    'position' => $position,
                ]);
            }

            (new SavePage)($page, [
                'type' => $page->type,
                'slug' => $page->slug,
                'title' => $page->title,
                'excerpt' => $page->excerpt,
                'seo' => $page->seo ?? [],
                'schema_type' => $page->schema_type,
                'sort' => $page->sort,
            ], $page->blocks->map(fn (PageBlock $block): array => [
                'id' => $block->id,
                'type' => $block->type->value,
                'data' => $block->data,
                'settings' => $block->settings,
                'visible' => $block->visible,
            ])->all(), $page->isPublished(), $user);

            PageCache::invalidate($page);
            Menus::flush();
        });

        return $page->fresh(['blocks']);
    }
}
