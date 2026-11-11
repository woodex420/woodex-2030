<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Safe to run on production at any time (idempotent): roles, stages, numbering, rate book, templates.
 * Contains public business data only. No people, no credentials, no bank numbers.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            PipelineStageSeeder::class,
            NumberSequenceSeeder::class,
            RateBookSeeder::class,
            ChartOfAccountsSeeder::class,
            QuoteTemplateSeeder::class,
            CannedRepliesSeeder::class,
            AutomationSequenceSeeder::class,
            ThemeTokensSeeder::class,
            PageTemplatesSeeder::class,
        ]);
    }
}
