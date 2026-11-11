<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Models\Category;
use Illuminate\Support\Facades\DB;

/** Missing categories only: never overwrite an operator's name, parent, sort order or activation. */
final class InstallCatalogueTaxonomy
{
    public function handle(): void
    {
        $taxonomy = json_decode(file_get_contents(resource_path('data/commerce-taxonomy.json')), true, flags: JSON_THROW_ON_ERROR);
        activity()->withoutLogging(function () use ($taxonomy): void {
            DB::transaction(function () use ($taxonomy): void {
                foreach ($taxonomy['segments'] as $si => $segment) {
                    $root = Category::firstOrCreate(['slug' => $segment['slug']], ['name' => $segment['name'], 'position' => $si, 'is_active' => true]);
                    foreach ($segment['groups'] as $gi => $group) {
                        $parent = Category::firstOrCreate(['slug' => $group['id']], ['name' => $group['name'], 'parent_id' => $root->id, 'position' => $gi, 'is_active' => true]);
                        foreach ($group['children'] as $ci => $child) {
                            Category::firstOrCreate(['slug' => $child['id']], ['name' => $child['name'], 'parent_id' => $parent->id, 'position' => $ci, 'is_active' => true]);
                        }
                    }
                }
            });
        });
    }
}
