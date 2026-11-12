<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Services;

use App\Domain\Commerce\Models\Series;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Native collection snapshots only. Never changes legacy menu identities or product assignments. */
final class SeriesContent
{
    public function defaults(Series $series): array
    {
        return ['heading' => $series->name, 'intro' => '', 'seo_title' => $series->name, 'seo_description' => '', 'product_ids' => []];
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

            return ['id' => (int) $row->id, 'revision_id' => (int) $row->revision_id, 'name' => $content['name'], 'slug' => $content['slug']];
        })->all();
    }

    public function products(array $content): array
    {
        $byId = array_column($this->eligibleProducts(), null, 'id');

        return array_values(array_filter(array_map(fn ($id) => $byId[$id] ?? null, $content['product_ids'])));
    }

    public function validate(array $content): array
    {
        $valid = Validator::make($content, [
            'heading' => ['required', 'string', 'max:160'], 'intro' => ['nullable', 'string', 'max:5000'],
            'seo_title' => ['required', 'string', 'max:160'], 'seo_description' => ['nullable', 'string', 'max:320'],
            'product_ids' => ['present', 'array', 'max:100'],
            'product_ids.*' => ['required', 'integer', 'distinct'],
        ])->validate();
        $valid['product_ids'] = array_values(array_map('intval', $valid['product_ids']));
        if (array_diff($valid['product_ids'], array_column($this->eligibleProducts(), 'id'))) {
            throw ValidationException::withMessages(['product_ids' => 'Select only currently published, non-archived native products.']);
        }

        return $valid;
    }

    public function fingerprint(Series $series, array $content, ?array $products = null): string
    {
        return hash('sha256', json_encode([$series->id, $series->slug, $series->name, $series->is_active, $content, $products ?? $this->products($content)], JSON_THROW_ON_ERROR));
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
            activity('commerce')->performedOn($locked)->causedBy($user)->withProperties(['revision_id' => $id])->log('Series manual collection draft saved');

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
