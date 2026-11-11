<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Services;

use App\Domain\Commerce\Models\Category;
use App\Domain\Commerce\Models\CategoryContentRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CategoryContent
{
    public function formattedText(string $text, string $format = 'plain'): string
    {
        if ($format !== 'markdown') {
            return nl2br(e($text));
        }

        return strip_tags(Str::markdown($text, [
            'html_input' => 'strip', 'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            'renderer' => ['soft_break' => "<br>\n"],
        ]), '<p><br><strong><em><h1><h2><h3><h4><h5><h6><ul><ol><li><a><blockquote><code><pre><hr>');
    }

    public function defaults(Category $category): array
    {
        return ['seo_title' => $category->name, 'seo_description' => '', 'og_title' => '', 'og_description' => '',
            'canonical_path' => '/shop?category='.$category->slug, 'allow_indexing' => false,
            'hero_template' => 'banner', 'hero_heading' => $category->name, 'hero_text' => '', 'hero_alt' => '',
            'cta_label' => '', 'cta_path' => '', 'intro_heading' => '', 'intro_text' => '',
            'bottom_heading' => '', 'bottom_text' => '', 'text_format' => 'markdown', 'faqs' => [],
            'carousel_heading' => 'Featured products', 'carousel_mode' => 'off', 'carousel_sort' => 'catalogue',
            'carousel_limit' => 8, 'product_ids' => []];
    }

    /** Repository reference keys, not live inventory or verified best sellers. */
    public function eligibleProducts(Category $category): array
    {
        $all = Category::query()->get(['id', 'parent_id', 'slug'])->keyBy('id');
        $ids = [$category->id];
        for ($i = 0; $i < count($ids); $i++) {
            foreach ($all as $row) {
                if ($row->parent_id === $ids[$i] && ! in_array($row->id, $ids, true)) {
                    $ids[] = $row->id;
                }
            }
        }
        $slugs = $all->only($ids)->pluck('slug')->all();
        $aliases = json_decode(file_get_contents(resource_path('data/commerce-taxonomy.json')), true, flags: JSON_THROW_ON_ERROR)['aliases'];
        $slugs = array_map(fn ($slug) => $aliases[$slug] ?? $slug, $slugs);
        $items = json_decode(file_get_contents(resource_path('data/catalogue-reference.json')), true, flags: JSON_THROW_ON_ERROR)['items'];

        return array_values(array_filter($items, fn ($p) => in_array($p['category'], $slugs, true) || in_array($p['subcategory'] ?? '', $slugs, true)));
    }

    public function validate(Category $category, array $content, ?string $heroPath = null): array
    {
        $pathRule = function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value) && $value !== '' && (! str_starts_with($value, '/') || str_starts_with($value, '//') || preg_match('/[\\\\\x00-\x20]/', $value))) {
                $fail('Use a local path beginning with one slash, without spaces.');
            }
        };
        $rules = [
            'text_format' => ['sometimes', 'required', Rule::in(['plain', 'markdown'])],
            'seo_title' => ['required', 'string', 'max:160'], 'seo_description' => ['nullable', 'string', 'max:320'],
            'og_title' => ['nullable', 'string', 'max:160'], 'og_description' => ['nullable', 'string', 'max:320'],
            'canonical_path' => ['required', 'string', 'max:300', $pathRule], 'allow_indexing' => ['required', 'boolean'],
            'hero_template' => ['required', Rule::in(['banner', 'split', 'minimal'])],
            'hero_heading' => ['required', 'string', 'max:160'], 'hero_text' => ['nullable', 'string', 'max:2000'],
            'hero_alt' => [$heroPath ? 'required' : 'nullable', 'string', 'max:200'],
            'cta_label' => ['nullable', 'required_with:cta_path', 'string', 'max:80'],
            'cta_path' => ['nullable', 'required_with:cta_label', 'string', 'max:300', $pathRule],
            'intro_heading' => ['nullable', 'string', 'max:160'], 'intro_text' => ['nullable', 'string', 'max:5000'],
            'bottom_heading' => ['nullable', 'string', 'max:160'], 'bottom_text' => ['nullable', 'string', 'max:10000'],
            'faqs' => ['array', 'max:20'], 'faqs.*.question' => ['required', 'string', 'max:250'], 'faqs.*.answer' => ['required', 'string', 'max:2500'],
            'carousel_heading' => ['required', 'string', 'max:120'], 'carousel_mode' => ['required', Rule::in(['off', 'manual', 'automatic'])],
            'carousel_sort' => ['required', Rule::in(['catalogue', 'name'])], 'carousel_limit' => ['required', 'integer', 'between:1,12'],
            'product_ids' => ['array', 'max:12'], 'product_ids.*' => ['required', 'string', 'distinct', Rule::in(array_column($this->eligibleProducts($category), 'id'))],
        ];
        $valid = Validator::make($content, $rules)->validate();
        // Do not retain unvalidated nested keys or a browser-supplied storage path.
        $valid['faqs'] = array_map(fn ($faq) => ['question' => $faq['question'], 'answer' => $faq['answer']], array_values($valid['faqs']));
        if ($heroPath !== null && (! preg_match('~^commerce/category-heroes/[a-zA-Z0-9]+\.(jpg|jpeg|png|webp)$~', $heroPath) || ! Storage::disk('local')->exists($heroPath))) {
            throw ValidationException::withMessages(['hero' => 'The saved hero image is unavailable. Upload a replacement before publishing.']);
        }
        $valid['carousel_limit'] = (int) $valid['carousel_limit'];
        $valid['allow_indexing'] = (bool) $valid['allow_indexing'];
        $valid['product_ids'] = array_values($valid['product_ids']);
        $valid['hero_path'] = $heroPath;

        return $valid;
    }

    public function saveDraft(Category $category, array $content, ?string $heroPath, int $version, User $user): CategoryContentRevision
    {
        $this->authorize($user);

        return DB::transaction(function () use ($category, $content, $heroPath, $version, $user) {
            $locked = Category::query()->lockForUpdate()->findOrFail($category->id);
            $this->checkVersion($locked, $version);
            $valid = $this->validate($locked, $content, $heroPath);
            $id = DB::table('category_content_revisions')->insertGetId(['category_id' => $locked->id, 'content' => json_encode($valid, JSON_THROW_ON_ERROR), 'created_by' => $user->id, 'created_at' => now()]);
            $locked->forceFill(['draft_revision_id' => $id, 'content_version' => $version + 1])->save();
            activity('commerce')->performedOn($locked)->causedBy($user)->withProperties(['revision_id' => $id])->log('Category content draft saved');

            return CategoryContentRevision::findOrFail($id);
        });
    }

    public function publish(Category $category, int $revisionId, int $version, User $user): void
    {
        $this->authorize($user);
        abort_unless($user->can('commerce.catalog.publish'), 403);
        DB::transaction(function () use ($category, $revisionId, $version, $user): void {
            $locked = Category::query()->lockForUpdate()->findOrFail($category->id);
            $this->checkVersion($locked, $version);
            abort_unless((int) $locked->draft_revision_id === $revisionId, 409, 'Only the current draft can be published.');
            $revision = CategoryContentRevision::where('category_id', $locked->id)->findOrFail($revisionId);
            if (! DB::table('category_content_previews')->where('revision_id', $revisionId)->where('user_id', $user->id)->where('context_hash', $this->previewFingerprint($locked, $revision->content))->exists()) {
                throw ValidationException::withMessages(['publish' => 'Open the saved draft preview before publishing this exact revision.']);
            }
            $this->validate($locked, $revision->content, $revision->content['hero_path'] ?? null);
            $locked->forceFill(['published_revision_id' => $revisionId, 'content_published_at' => now(), 'content_version' => $version + 1])->save();
            activity('commerce')->performedOn($locked)->causedBy($user)->withProperties(['revision_id' => $revisionId])->log('Category content published to native snapshot only');
        });
    }

    public function unpublish(Category $category, int $version, User $user): void
    {
        $this->authorize($user);
        abort_unless($user->can('commerce.catalog.publish'), 403);
        DB::transaction(function () use ($category, $version, $user): void {
            $locked = Category::query()->lockForUpdate()->findOrFail($category->id);
            $this->checkVersion($locked, $version);
            if ($locked->published_revision_id === null) {
                return;
            }
            $locked->forceFill(['published_revision_id' => null, 'content_published_at' => null, 'content_version' => $version + 1])->save();
            activity('commerce')->performedOn($locked)->causedBy($user)->log('Category native publication removed; revisions retained');
        });
    }

    public function products(Category $category, array $content): array
    {
        if ($content['carousel_mode'] === 'off') {
            return [];
        }
        $items = $this->eligibleProducts($category);
        if ($content['carousel_mode'] === 'manual') {
            $byId = array_column($items, null, 'id');
            $items = array_values(array_filter(array_map(fn ($id) => $byId[$id] ?? null, $content['product_ids'])));
        } elseif ($content['carousel_sort'] === 'name') {
            usort($items, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        }

        return array_slice($items, 0, $content['carousel_limit']);
    }

    public function previewFingerprint(Category $category, array $content): string
    {
        return hash('sha256', json_encode(['slug' => $category->slug, 'content' => $content, 'products' => $this->products($category, $content)], JSON_THROW_ON_ERROR));
    }

    private function authorize(User $user): void
    {
        abort_unless($user->can('commerce.catalog.view') && $user->can('commerce.catalog.manage'), 403);
    }

    private function checkVersion(Category $category, int $version): void
    {
        if ((int) $category->content_version !== $version) {
            throw ValidationException::withMessages(['conflict' => 'This category content changed in another tab. Reload before saving or publishing.']);
        }
    }
}
