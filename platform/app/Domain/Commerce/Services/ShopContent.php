<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Services;

use App\Domain\Commerce\Models\Category;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Native configuration only; never switches the storefront or supplies checkout prices. */
final class ShopContent
{
    public function defaults(): array
    {
        return ['heading' => 'Shop', 'intro' => '', 'seo_title' => 'Shop', 'seo_description' => '', 'sections' => []];
    }

    public function section(): array
    {
        return ['heading' => '', 'type' => 'manual', 'category_id' => '', 'series_id' => '', 'product_ids' => [], 'limit' => 8, 'sort' => 'source'];
    }

    public function normalize(array $content): array
    {
        if (isset($content['sections']) && is_array($content['sections'])) {
            $content['sections'] = array_map(fn ($section) => is_array($section) ? array_replace($this->section(), $section) : $section, $content['sections']);
        }

        return $content;
    }

    public function state(bool $lock = false): object
    {
        $query = DB::table('commerce_shop_state')->where('id', 1);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    public function revision(int $id): object
    {
        $row = DB::table('shop_content_revisions')->where('id', $id)->first();
        abort_unless($row, 404);

        return $row;
    }

    public function validate(array $content): array
    {
        $content = $this->normalize($content);
        $valid = Validator::make($content, [
            'heading' => ['required', 'string', 'max:160'], 'intro' => ['nullable', 'string', 'max:5000'],
            'seo_title' => ['required', 'string', 'max:160'], 'seo_description' => ['nullable', 'string', 'max:320'],
            'sections' => ['present', 'array', 'max:12'],
            'sections.*.heading' => ['required', 'string', 'max:120'],
            'sections.*.type' => ['required', Rule::in(['manual', 'category', 'series', 'hot', 'featured', 'sale'])],
            'sections.*.category_id' => ['nullable', 'integer'], 'sections.*.series_id' => ['nullable', 'integer'],
            'sections.*.sort' => ['required', Rule::in(['source', 'name', 'featured'])],
            'sections.*.limit' => ['required', 'integer', 'between:1,24'],
            'sections.*.product_ids' => ['present', 'array', 'max:24'], 'sections.*.product_ids.*' => ['required', 'integer'],
        ])->validate();
        $eligible = array_column(app(SeriesContent::class)->eligibleProducts(), 'id');
        $sections = [];
        foreach ($valid['sections'] as $i => $section) {
            $section = array_replace($this->section(), array_intersect_key($section, $this->section()));
            $section['product_ids'] = array_values(array_map('intval', $section['product_ids']));
            $section['category_id'] = filled($section['category_id']) ? (int) $section['category_id'] : '';
            $section['series_id'] = filled($section['series_id']) ? (int) $section['series_id'] : '';
            $section['limit'] = (int) $section['limit'];
            if (count(array_unique($section['product_ids'])) !== count($section['product_ids']) || ($section['type'] === 'manual' && array_diff($section['product_ids'], $eligible))) {
                throw ValidationException::withMessages(['sections.'.$i.'.product_ids' => 'Choose distinct currently published, non-archived native products.']);
            }
            if ($section['type'] === 'category' && (! $section['category_id'] || ! Category::whereKey($section['category_id'])->exists())) {
                throw ValidationException::withMessages(['sections.'.$i.'.category_id' => 'Choose an existing category branch.']);
            }
            if ($section['type'] === 'series' && ! $this->publishedSeries((int) $section['series_id'])) {
                throw ValidationException::withMessages(['sections.'.$i.'.series_id' => 'Choose a Series with an owned native publication.']);
            }
            $sections[] = $section;
        }
        $valid['sections'] = $sections;

        return $valid;
    }

    private function publishedSeries(int $id): ?object
    {
        return DB::table('commerce_series as s')->join('series_content_revisions as r', function ($join): void {
            $join->on('r.id', '=', 's.published_revision_id')->on('r.series_id', '=', 's.id');
        })->where('s.id', $id)->first(['r.id', 'r.content']);
    }

    public function project(array $content, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now('UTC');
        $content = $this->normalize($content);
        $service = app(SeriesContent::class);
        $eligible = $service->eligibleProducts();
        $byId = array_column($eligible, null, 'id');
        // IDs originate only from the product-owned publications above; revision content is immutable.
        $details = DB::table('commerce_product_revisions')->whereIn('id', array_column($eligible, 'revision_id'))->get(['id', 'content'])->mapWithKeys(fn ($row) => [(int) $row->id => json_decode($row->content, true, flags: JSON_THROW_ON_ERROR)])->all();
        $onSale = function (array $product) use ($details, $at): bool {
            $data = $details[$product['revision_id']] ?? [];
            $pricing = app(ProductMerchandising::class);
            foreach ([$data, ...($data['variants'] ?? [])] as $row) {
                if ($pricing->isOnSale($row, $at)) {
                    return true;
                }
            }

            return false;
        };
        $categories = Category::all();
        $result = [];
        foreach ($content['sections'] as $section) {
            $products = [];
            if ($section['type'] === 'manual') {
                $products = array_values(array_filter(array_map(fn ($id) => $byId[$id] ?? null, $section['product_ids'])));
            } elseif ($section['type'] === 'series') {
                $revision = $this->publishedSeries((int) $section['series_id']);
                if ($revision) {
                    $products = $service->products(json_decode($revision->content, true, flags: JSON_THROW_ON_ERROR));
                }
            } else {
                $branch = $section['type'] === 'category' && $categories->contains('id', (int) $section['category_id']) ? app(CategoryImpact::class)->branch($categories, (int) $section['category_id']) : [];
                $products = array_values(array_filter($eligible, fn ($p) => match ($section['type']) {
                    'category' => (bool) array_intersect($p['category_ids'], $branch),
                    'hot' => $p['is_hot'], 'featured' => $p['is_featured'], 'sale' => $onSale($p), default => false,
                }));
            }
            // Sort before limiting; preserve source/manual/Series order by default.
            if ($section['sort'] === 'name') {
                usort($products, fn ($a, $b) => strcasecmp($a['name'], $b['name']) ?: $a['id'] <=> $b['id']);
            } elseif ($section['sort'] === 'featured') {
                usort($products, fn ($a, $b) => ((int) ($details[$a['revision_id']]['featured_position'] ?? 0) <=> (int) ($details[$b['revision_id']]['featured_position'] ?? 0)) ?: $a['id'] <=> $b['id']);
            }
            $result[] = ['heading' => $section['heading'], 'type' => $section['type'], 'sort' => $section['sort'], 'products' => array_slice($products, 0, max(1, min(24, (int) $section['limit'])))];
        }

        return $result;
    }

    public function fingerprint(array $content, ?array $projection = null): string
    {
        return hash('sha256', json_encode([$this->normalize($content), $projection ?? $this->project($content)], JSON_THROW_ON_ERROR));
    }

    private function check(object $state, int $version): void
    {
        if ((int) $state->version !== $version) {
            throw ValidationException::withMessages(['conflict' => 'Shop configuration changed in another session. Reload before continuing.']);
        }
    }

    public function save(array $content, int $version, User $user): int
    {
        app(ProductCatalogue::class)->authorize($user);

        return DB::transaction(function () use ($content, $version, $user): int {
            $state = $this->state(true);
            $this->check($state, $version);
            $valid = $this->validate($content);
            $id = DB::table('shop_content_revisions')->insertGetId(['content' => json_encode($valid, JSON_THROW_ON_ERROR), 'created_by' => $user->id, 'created_at' => now()]);
            DB::table('commerce_shop_state')->where('id', 1)->update(['version' => $version + 1, 'draft_revision_id' => $id]);
            activity('commerce')->causedBy($user)->withProperties(['revision_id' => $id])->log('Shop section draft saved; no storefront change');

            return $id;
        });
    }

    public function publish(int $id, int $version, User $user): void
    {
        app(ProductCatalogue::class)->authorize($user, true);
        DB::transaction(function () use ($id, $version, $user): void {
            $state = $this->state(true);
            $this->check($state, $version);
            abort_unless((int) $state->draft_revision_id === $id, 409);
            $content = $this->validate(json_decode($this->revision($id)->content, true, flags: JSON_THROW_ON_ERROR));
            if (! DB::table('shop_content_previews')->where('revision_id', $id)->where('user_id', $user->id)->where('context_hash', $this->fingerprint($content))->exists()) {
                throw ValidationException::withMessages(['publish' => 'Open this exact saved preview with current published products before publishing.']);
            }
            DB::table('commerce_shop_state')->where('id', 1)->update(['version' => $version + 1, 'published_revision_id' => $id]);
            activity('commerce')->causedBy($user)->withProperties(['revision_id' => $id])->log('Shop configuration published natively; React storefront unchanged');
        });
    }

    public function withdraw(int $version, User $user): void
    {
        app(ProductCatalogue::class)->authorize($user, true);
        DB::transaction(function () use ($version, $user): void {
            $this->check($this->state(true), $version);
            DB::table('commerce_shop_state')->where('id', 1)->update(['version' => $version + 1, 'published_revision_id' => null]);
            activity('commerce')->causedBy($user)->log('Shop native publication withdrawn; revisions retained');
        });
    }
}
