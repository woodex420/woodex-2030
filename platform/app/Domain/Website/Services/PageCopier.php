<?php

declare(strict_types=1);

namespace App\Domain\Website\Services;

use App\Domain\Website\Enums\PageStatus;
use App\Domain\Website\Models\Page;

/**
 * Copies a page and its blocks into a draft (Pages → Duplicate, and the ported Service pages screen's
 * “New service page”, which starts from an existing layout because that is how the v2.1 admin worked).
 *
 * One implementation on purpose: two copies drifting apart would mean a duplicated page behaving
 * differently depending on which screen made it.
 */
final class PageCopier
{
    /**
     * @param  string|null  $title  the copy's title; default “<title> (copy)”
     * @param  string|null  $slug  the copy's address; default “<slug>-copy/”, numbered until it is free
     */
    public function copy(Page $page, ?string $title = null, ?string $slug = null): Page
    {
        $copy = $page->replicate(['slug', 'published_at']);

        $copy->title = $title ?? $page->title.' (copy)';
        $copy->slug = $slug === null ? $this->freeSlug('/'.trim($page->slug, '/').'-copy/') : Page::normalizeSlug($slug);
        $copy->status = PageStatus::Draft;
        $copy->published_at = null;
        $copy->save();

        foreach ($page->blocks as $block) {
            $copy->blocks()->create([
                'type' => $block->type,
                'data' => $block->data,
                'visible' => $block->visible,
                'position' => $block->position,
            ]);
        }

        return $copy;
    }

    /** “-copy”, “-copy-2”, “-copy-3” … until the address is free. */
    private function freeSlug(string $slug): string
    {
        $base = rtrim(Page::normalizeSlug($slug), '/');
        $candidate = $base.'/';
        $n = 2;

        while (Page::query()->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.$n.'/';
            $n++;
        }

        return $candidate;
    }
}
