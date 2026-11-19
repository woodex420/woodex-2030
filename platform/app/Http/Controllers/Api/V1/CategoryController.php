<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Commerce\Models\Category;
use App\Domain\Commerce\Models\CategoryContentRevision;
use App\Domain\Commerce\Models\Series;
use App\Domain\Commerce\Services\CategoryContent;
use App\Http\Controllers\Controller;
use App\Support\Api\ApiAnswer;
use App\Support\Api\Problem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * GET /api/v1/catalogue/categories: the category tree as it is published, with the page of each category that has
 * one, and the series. One document, always whole: the shop builds its menu from it and cannot work with a part.
 *
 * GET /api/v1/catalogue/categories/{category}/hero/{revision}: the picture of a published category page.
 */
final class CategoryController extends Controller
{
    /**
     * What a published page hands to the shop: these keys and no others. What the editor works with besides (the
     * text as it was typed, the choice of products, the settings of the carousel) stays here, and a field added to
     * the editor later is not handed out by accident.
     */
    private const array PAGE = [
        'seo_title', 'seo_description', 'og_title', 'og_description', 'canonical_path', 'allow_indexing',
        'hero_template', 'hero_heading', 'hero_text', 'hero_alt', 'hero_url', 'cta_label', 'cta_path',
        'top_html', 'intro_heading', 'intro_html', 'bottom_heading', 'bottom_html',
        'carousel_heading', 'carousel_mode', 'featured_ids', 'faqs',
    ];

    public function index(CategoryContent $service): JsonResponse
    {
        if (! config('woodex.category_sync_enabled')) {
            throw new Problem(503, 'UNAVAILABLE', 'The category list is switched off at the moment.');
        }

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
                $page = ['revision' => $revision->id, 'content' => self::handedOut($content)];
            }

            return ['id' => $category->id, 'name' => $category->name, 'slug' => $category->slug, 'parent_id' => $category->parent_id, 'position' => $category->position, 'page' => $page];
        })->values()->all();

        return ApiAnswer::one([
            'categories' => $categories,
            'series' => Series::where('is_active', true)->orderBy('position')->orderBy('id')->get(['slug', 'name'])->map(fn ($s) => ['id' => $s->slug, 'name' => $s->name])->all(),
        ]);
    }

    public function hero(Category $category, CategoryContentRevision $revision): BinaryFileResponse
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

        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    /**
     * The keys of PAGE, each in the kind the contract names: a text is a text even where the editor left it empty.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private static function handedOut(array $content): array
    {
        $page = [];

        foreach (self::PAGE as $key) {
            $value = $content[$key] ?? null;

            $page[$key] = match ($key) {
                'allow_indexing' => (bool) $value,
                'hero_url' => is_string($value) ? $value : null,
                'featured_ids' => array_values(array_map(strval(...), (array) $value)),
                'faqs' => array_values(array_map(static fn (mixed $faq): array => [
                    'question' => (string) (is_array($faq) ? ($faq['question'] ?? '') : ''),
                    'answer' => (string) (is_array($faq) ? ($faq['answer'] ?? '') : ''),
                ], (array) $value)),
                default => (string) $value,
            };
        }

        return $page;
    }
}
