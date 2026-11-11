<?php

declare(strict_types=1);

use Database\Seeders\ProductionSeeder;
use Illuminate\Support\Facades\DB;

/*
| Outside the test suite a Furniture installation must not receive the Interior studio's prices, quotation
| templates, message copy, website theme or page templates (owner decision D-8: nothing is invented, and nothing
| of Interior's is reused as if it were Furniture's).
*/

it('seeds only business-neutral reference rows outside the test suite', function () {
    $neutral = ProductionSeeder::seeders(withInteriorReference: false);

    expect($neutral)->toBe(ProductionSeeder::GENERIC)
        ->and(array_intersect($neutral, ProductionSeeder::INTERIOR_REFERENCE))->toBe([])
        ->and(ProductionSeeder::seeders(withInteriorReference: true))
        ->toEqualCanonicalizing([...ProductionSeeder::GENERIC, ...ProductionSeeder::INTERIOR_REFERENCE]);
});

it('leaves the Interior price book, templates and message copy out of a neutral seed', function () {
    $interiorTables = ['catalog_items', 'estimator_rates', 'quote_templates', 'canned_replies', 'automation_sequences', 'theme_tokens', 'page_templates'];

    // The suite's own database was seeded with the Interior reference rows; empty them, then seed as an installation would.
    foreach ($interiorTables as $table) {
        DB::table($table)->delete();
    }

    $this->seed(ProductionSeeder::seeders(withInteriorReference: false));

    foreach ($interiorTables as $table) {
        expect(DB::table($table)->count())->toBe(0, "{$table} must stay empty");
    }
    expect(DB::table('roles')->count())->toBe(8)
        ->and(DB::table('pipeline_stages')->count())->toBeGreaterThan(0)
        ->and(DB::table('number_sequences')->count())->toBeGreaterThan(0)
        ->and(DB::table('chart_of_accounts')->count())->toBeGreaterThan(0);
});
