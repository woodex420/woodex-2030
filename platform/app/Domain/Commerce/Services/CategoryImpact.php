<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Services;

use App\Domain\Commerce\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read-only classification evidence. Counts are distinct products, not assignment totals. */
final class CategoryImpact
{
    public function branch(Collection $categories, int $id): array
    {
        $children = $categories->groupBy('parent_id');
        $ids = [];
        $pending = [$id];
        while ($pending) {
            $next = array_pop($pending);
            if (isset($ids[$next])) {
                continue;
            }
            $ids[$next] = $next;
            foreach ($children->get($next, []) as $child) {
                $pending[] = $child->id;
            }
        }

        return array_values($ids);
    }

    public function path(Collection $categories, ?int $id): string
    {
        $byId = $categories->keyBy('id');
        $names = [];
        $seen = [];
        while ($id !== null && isset($byId[$id]) && ! isset($seen[$id])) {
            $seen[$id] = true;
            array_unshift($names, $byId[$id]->name.(! $byId[$id]->is_active ? ' · inactive' : ''));
            $id = $byId[$id]->parent_id;
        }

        return implode(' → ', $names) ?: 'Top level';
    }

    public function counts(Collection $categories, bool $includeDrafts): array
    {
        $drafts = $includeDrafts ? Product::whereNull('archived_at')->get(['id', 'draft'])->map(fn ($p) => $p->draft['category_ids'] ?? [])->all() : [];
        // Product ownership matters: a foreign publication pointer is not a valid snapshot.
        $published = DB::table('commerce_products as p')->join('commerce_product_revisions as r', function ($join): void {
            $join->on('r.id', '=', 'p.published_revision_id')->on('r.product_id', '=', 'p.id');
        })->whereNull('p.archived_at')->pluck('r.content')->map(fn ($json) => json_decode($json, true, flags: JSON_THROW_ON_ERROR)['category_ids'] ?? [])->all();
        $result = [];
        foreach ($categories as $category) {
            $branch = $this->branch($categories, $category->id);
            $count = fn (array $rows, array $ids): int => count(array_filter($rows, fn ($assigned) => (bool) array_intersect($assigned, $ids)));
            $result[$category->id] = [
                'direct_published' => $count($published, [$category->id]),
                'branch_published' => $count($published, $branch),
            ];
            if ($includeDrafts) {
                $result[$category->id]['direct_draft'] = $count($drafts, [$category->id]);
                $result[$category->id]['branch_draft'] = $count($drafts, $branch);
            }
        }

        return $result;
    }
}
