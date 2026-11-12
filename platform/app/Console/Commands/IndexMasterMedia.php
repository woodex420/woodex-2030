<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Commerce\Models\ProductMedia;
use App\Domain\Media\Services\MasterMedia;
use App\Domain\Website\Models\Media;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class IndexMasterMedia extends Command
{
    protected $signature = 'media:index-masters';

    protected $description = 'Non-destructively retain and index website/product images and product revision usages; no publishing';

    public function handle(MasterMedia $master): int
    {
        $indexed = $skipped = 0;
        foreach ([Media::class, ProductMedia::class] as $model) {
            foreach ($model::orderBy('id')->lazyById() as $source) {
                try {
                    $master->register($source);
                    $indexed++;
                } catch (ValidationException $exception) {
                    $this->warn($model.' #'.$source->id.': '.json_encode($exception->errors()));
                    $skipped++;
                }
            }
        }
        foreach (DB::table('commerce_product_revisions')->orderBy('id')->lazyById() as $revision) {
            try {
                $master->recordRevision((int) $revision->product_id, (int) $revision->id, json_decode($revision->content, true, flags: JSON_THROW_ON_ERROR)['images'] ?? []);
            } catch (ValidationException|ModelNotFoundException $exception) {
                $this->warn('Revision #'.$revision->id.' has unavailable media.');
                $skipped++;
            }
        }
        $this->info("Indexed {$indexed} sources; {$skipped} unavailable sources/revisions. Originals untouched; no publishing.");

        return $skipped ? self::FAILURE : self::SUCCESS;
    }
}
