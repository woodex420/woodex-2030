<?php

declare(strict_types=1);

namespace App\Domain\Website\Actions;

use App\Domain\Website\Enums\PageStatus;
use App\Domain\Website\Models\Page;
use App\Domain\Website\Models\PageTemplate;
use App\Domain\Website\Services\PageCache;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Start a page from a saved layout (V2-3 F5, docs/14 §5a): the template decides which sections the
 * page opens with and in what order; the text stays the page's own. The page lands as a draft —
 * nothing is public until it is edited and published like any other page.
 *
 * The URL is derived from the template name and made unique (`/kitchen/`, `/kitchen-2/`), so two
 * people pressing "Create page" on the same card never fight over a slug.
 */
final class CreatePageFromTemplate
{
    public function handle(PageTemplate $template, ?User $by = null): Page
    {
        $base = Str::slug($template->name) ?: 'page';
        $slug = '/'.$base.'/';

        for ($i = 2; Page::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = '/'.$base.'-'.$i.'/';
        }

        $page = DB::transaction(function () use ($template, $by, $slug): Page {
            $page = Page::query()->create([
                'type' => $template->page_type,
                'slug' => $slug,
                'title' => $template->name,
                'status' => PageStatus::Draft,
                'author_id' => $by?->id,
            ]);

            foreach ($template->toBlocks() as $position => $block) {
                $page->blocks()->create([
                    'type' => $block['type'],
                    'block_key' => $block['key'],
                    'data' => $block['data'],
                    'visible' => true,
                    'position' => $position,
                ]);
            }

            return $page;
        });

        // A new draft has no public URL, but the preview frame reads this page from the cache key.
        PageCache::invalidate($page);

        return $page;
    }
}
