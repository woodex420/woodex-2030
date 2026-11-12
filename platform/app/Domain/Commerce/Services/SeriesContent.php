<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Services;

use App\Domain\Commerce\Models\Category;
use App\Domain\Commerce\Models\Series;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Native collection snapshots only. Never changes legacy menu identities or product assignments. */
final class SeriesContent
{
    public function defaults(Series $series): array
    {
        return ['heading' => $series->name, 'intro' => '', 'seo_title' => $series->name, 'seo_description' => '', 'product_ids' => [], 'membership_mode' => 'manual', 'rule_category_id' => '', 'rule_hot' => false, 'rule_featured' => false, 'rule_sort' => 'catalogue', 'rule_limit' => 12];
    }

    public function create(array $identity, User $user): Series
    {
        app(ProductCatalogue::class)->authorize($user);
        $name = $identity['name'] ?? '';
        $slug = $identity['slug'] ?? '';
        $identity = ['name' => is_string($name) ? trim($name) : $name, 'slug' => is_string($slug) ? strtolower(trim($slug)) : $slug];
        $valid = Validator::make($identity, ['name' => ['required', 'string', 'max:120'], 'slug' => ['required', 'string', 'max:140', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:commerce_series,slug']])->validate();

        return DB::transaction(function () use ($valid, $user): Series {
            $series = Series::create($valid + ['is_active' => false, 'position' => (int) Series::max('position') + 1]);
            $this->save($series, $this->defaults($series), 0, $user);
            activity('commerce')->performedOn($series)->causedBy($user)->log('Inactive Series identity and initial draft created; no menu activation');

            return $series->fresh();
        });
    }

    public function normalize(array $content): array
    {
        return array_replace(['membership_mode' => 'manual', 'rule_category_id' => '', 'rule_hot' => false, 'rule_featured' => false, 'rule_sort' => 'catalogue', 'rule_limit' => 12], $content);
    }

    public function revision(Series $series, int $id): object
    {
        $revision = DB::table('series_content_revisions')->where('series_id', $series->id)->where('id', $id)->first();
        abort_unless($revision, 404);

        return $revision;
    }

    /** Only product-owned publications, never a newer product draft. */
    public function eligibleProducts(): array
    {
        return DB::table('commerce_products as p')->join('commerce_product_revisions as r', function ($join): void {
            $join->on('r.id', '=', 'p.published_revision_id')->on('r.product_id', '=', 'p.id');
        })->whereNull('p.archived_at')->orderBy('p.id')->get(['p.id', 'r.id as revision_id', 'r.content'])->map(function ($row): array {
            $content = json_decode($row->content, true, flags: JSON_THROW_ON_ERROR);

            return ['id' => (int) $row->id, 'revision_id' => (int) $row->revision_id, 'name' => $content['name'], 'slug' => $content['slug'], 'category_ids' => $content['category_ids'] ?? [], 'is_hot' => (bool) ($content['is_hot'] ?? false), 'is_featured' => (bool) ($content['is_featured'] ?? false)];
        })->all();
    }

    public function products(array $content): array
    {
        $content = $this->normalize($content);
        $eligible = $this->eligibleProducts();
        if ($content['membership_mode'] === 'automatic') {
            $branch = null;
            if (filled($content['rule_category_id'])) {
                $categories = Category::all();
                // A removed rule category must never silently become "all products".
                if (! $categories->contains('id', (int) $content['rule_category_id'])) {
                    return [];
                }
                $branch = app(CategoryImpact::class)->branch($categories, (int) $content['rule_category_id']);
            }
            $eligible = array_values(array_filter($eligible, fn ($p) => (! $branch || array_intersect($p['category_ids'], $branch)) && (! $content['rule_hot'] || $p['is_hot']) && (! $content['rule_featured'] || $p['is_featured'])));
            if ($content['rule_sort'] === 'name') {
                usort($eligible, fn ($a, $b) => strcasecmp($a['name'], $b['name']) ?: $a['id'] <=> $b['id']);
            }

            return array_slice($eligible, 0, max(1, min(100, (int) $content['rule_limit'])));
        }
        $byId = array_column($eligible, null, 'id');

        return array_values(array_filter(array_map(fn ($id) => $byId[$id] ?? null, $content['product_ids'])));
    }

    public function validate(array $content): array
    {
        $content = $this->normalize($content);
        $valid = Validator::make($content, [
            'heading' => ['required', 'string', 'max:160'], 'intro' => ['nullable', 'string', 'max:5000'],
            'seo_title' => ['required', 'string', 'max:160'], 'seo_description' => ['nullable', 'string', 'max:320'],
            'membership_mode' => ['required', Rule::in(['manual', 'automatic'])],
            'rule_category_id' => ['nullable', 'integer', 'exists:commerce_categories,id'],
            'rule_hot' => ['required', 'boolean'], 'rule_featured' => ['required', 'boolean'],
            'rule_sort' => ['required', Rule::in(['catalogue', 'name'])], 'rule_limit' => ['required', 'integer', 'between:1,100'],
            'product_ids' => ['present', 'array', 'max:100'],
            'product_ids.*' => ['required', 'integer', 'distinct'],
        ])->validate();
        $valid['product_ids'] = array_values(array_map('intval', $valid['product_ids']));
        $valid['rule_category_id'] = filled($valid['rule_category_id']) ? (int) $valid['rule_category_id'] : '';
        $valid['rule_hot'] = (bool) $valid['rule_hot'];
        $valid['rule_featured'] = (bool) $valid['rule_featured'];
        $valid['rule_limit'] = (int) $valid['rule_limit'];
        if ($valid['membership_mode'] === 'manual' && array_diff($valid['product_ids'], array_column($this->eligibleProducts(), 'id'))) {
            throw ValidationException::withMessages(['product_ids' => 'Select only currently published, non-archived native products.']);
        }

        return $valid;
    }

    public function fingerprint(Series $series, array $content, ?array $products = null): string
    {
        return hash('sha256', json_encode([$series->id, $series->slug, $series->name, $series->is_active, $this->normalize($content), $products ?? $this->products($content)], JSON_THROW_ON_ERROR));
    }

    private function checkVersion(Series $series, int $version): void
    {
        if ((int) $series->content_version !== $version) {
            throw ValidationException::withMessages(['conflict' => 'This collection changed in another session. Reload before continuing.']);
        }
    }

    public function save(Series $series, array $content, int $version, User $user): int
    {
        app(ProductCatalogue::class)->authorize($user);

        return DB::transaction(function () use ($series, $content, $version, $user): int {
            $locked = Series::lockForUpdate()->findOrFail($series->id);
            $this->checkVersion($locked, $version);
            $valid = $this->validate($content);
            $id = DB::table('series_content_revisions')->insertGetId(['series_id' => $locked->id, 'content' => json_encode($valid, JSON_THROW_ON_ERROR), 'created_by' => $user->id, 'created_at' => now()]);
            $locked->forceFill(['draft_revision_id' => $id, 'content_version' => $version + 1])->save();
            activity('commerce')->performedOn($locked)->causedBy($user)->withProperties(['revision_id' => $id])->log('Series collection draft saved');

            return $id;
        });
    }

    public function publish(Series $series, int $revisionId, int $version, User $user): void
    {
        app(ProductCatalogue::class)->authorize($user, true);
        DB::transaction(function () use ($series, $revisionId, $version, $user): void {
            $locked = Series::lockForUpdate()->findOrFail($series->id);
            $this->checkVersion($locked, $version);
            abort_unless((int) $locked->draft_revision_id === $revisionId, 409);
            $revision = $this->revision($locked, $revisionId);
            $content = $this->validate(json_decode($revision->content, true, flags: JSON_THROW_ON_ERROR));
            if (! DB::table('series_content_previews')->where('revision_id', $revisionId)->where('user_id', $user->id)->where('context_hash', $this->fingerprint($locked, $content))->exists()) {
                throw ValidationException::withMessages(['publish' => 'Open the exact saved preview with the current published products before publishing.']);
            }
            $locked->forceFill(['published_revision_id' => $revisionId, 'content_version' => $version + 1])->save();
            activity('commerce')->performedOn($locked)->causedBy($user)->withProperties(['revision_id' => $revisionId])->log('Series content published to native snapshot only; no storefront cutover');
        });
    }

    public function withdraw(Series $series, int $version, User $user): void
    {
        app(ProductCatalogue::class)->authorize($user, true);
        DB::transaction(function () use ($series, $version, $user): void {
            $locked = Series::lockForUpdate()->findOrFail($series->id);
            $this->checkVersion($locked, $version);
            $locked->forceFill(['published_revision_id' => null, 'content_version' => $version + 1])->save();
            activity('commerce')->performedOn($locked)->causedBy($user)->log('Series native publication withdrawn; history retained');
        });
    }
}
