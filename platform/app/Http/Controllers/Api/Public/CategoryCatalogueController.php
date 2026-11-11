<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Domain\Commerce\Models\Category;
use App\Domain\Commerce\Models\CategoryContentRevision;
use App\Domain\Commerce\Models\Series;
use App\Domain\Commerce\Services\CategoryContent;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;

final class CategoryCatalogueController extends Controller
{
    public function index(CategoryContent $service)
    {
        abort_unless(config('woodex.category_sync_enabled'), 503);
        // Exclude inactive branches, including otherwise active descendants.
        $all = Category::orderBy('position')->orderBy('id')->get();
        $visible = collect();
        $visit = function ($parent, $depth = 0) use (&$visit, $all, $visible): void {
            if ($depth > 20) {
                return;
            }
            foreach ($all->where('parent_id', $parent)->where('is_active', true) as $row) {
                $visible->push($row);
                $visit($row->id, $depth + 1);
            }
        };
        $visit(null);
        $revisions = CategoryContentRevision::whereIn('id', $visible->pluck('published_revision_id')->filter())->get()->keyBy('id');
        $categories = $visible->map(function ($category) use ($revisions, $service) {
            $revision = $revisions->get($category->published_revision_id);
            $page = null;
            if ($revision && $revision->category_id === $category->id) {
                $content = $revision->content;
                $hero = $content['hero_path'] ?? null;
                $content['hero_url'] = $hero && Storage::disk('local')->exists($hero) ? '/api/v1/catalogue/categories/'.$category->id.'/hero/'.$revision->id : null;
                unset($content['hero_path']);
                $content['intro_html'] = $service->formattedText($content['intro_text'] ?? '', $content['text_format'] ?? 'plain');
                $content['bottom_html'] = $service->formattedText($content['bottom_text'] ?? '', $content['text_format'] ?? 'plain');
                // Eligibility is recalculated; a later tree move cannot expose unrelated manual cards.
                $eligible = array_column($service->eligibleProducts($category), 'id');
                $content['product_ids'] = array_values(array_intersect($content['product_ids'], $eligible));
                $content['top_html'] = view('commerce.category-content-blocks', ['placement' => 'top', 'content' => $content])->render();
                $content['bottom_html'] = view('commerce.category-content-blocks', ['placement' => 'bottom', 'content' => $content])->render();
                $content['featured_ids'] = array_column($service->products($category, $content), 'id');
                $page = ['revision' => $revision->id, 'content' => $content];
            }

            return ['id' => $category->id, 'name' => $category->name, 'slug' => $category->slug, 'parent_id' => $category->parent_id, 'position' => $category->position, 'page' => $page];
        })->values();
        $data = ['schema' => 1, 'categories' => $categories, 'series' => Series::where('is_active', true)->orderBy('position')->orderBy('id')->get(['slug', 'name'])->map(fn ($s) => ['id' => $s->slug, 'name' => $s->name])];

        return response()->json($data)->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }

    public function image(Category $category, CategoryContentRevision $revision)
    {
        abort_unless(config('woodex.category_sync_enabled') && $category->is_active && $category->published_revision_id === $revision->id && $revision->category_id === $category->id, 404);
        $parent = $category;
        $seen = [];
        while ($parent->parent_id !== null) {
            abort_if(isset($seen[$parent->id]), 404);
            $seen[$parent->id] = true;
            $parent = Category::findOrFail($parent->parent_id);
            abort_unless($parent->is_active, 404);
        }
        $path = $revision->content['hero_path'] ?? null;
        abort_unless(is_string($path) && preg_match('~^commerce/category-heroes/[a-zA-Z0-9]+\.(jpg|jpeg|png|webp)$~', $path) && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
