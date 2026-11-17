<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ProductionSeeder extends Seeder
{
    /** Reference rows every installation needs. Nothing here names a business. */
    public const array GENERIC = [
        RolesAndPermissionsSeeder::class,
        PipelineStageSeeder::class,
        NumberSequenceSeeder::class,
        ChartOfAccountsSeeder::class,
    ];

    /**
     * The Interior studio's reference content: its price book, quotation templates, message copy,
     * website theme and page templates.
     *
     * Woodex Furniture has not supplied its own yet (docs/laravel-os, owner decision D-8), and nothing is invented
     * in its place. Outside the test suite none of this is seeded; the tests inherited from WOODEX OS still rely
     * on these rows. Replace this list when the Furniture price list, terms and wording arrive.
     */
    public const array INTERIOR_REFERENCE = [
        RateBookSeeder::class,
        QuoteTemplateSeeder::class,
        CannedRepliesSeeder::class,
        AutomationSequenceSeeder::class,
        ThemeTokensSeeder::class,
        PageTemplatesSeeder::class,
    ];

    /** @return list<class-string<Seeder>> */
    public static function seeders(bool $withInteriorReference): array
    {
        if (! $withInteriorReference) {
            return self::GENERIC;
        }

        // The order the suite was written against: the rate book before the chart of accounts.
        return [
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
        ];
    }

    public function run(): void
    {
        $this->call(self::seeders(withInteriorReference: app()->runningUnitTests()));
    }
}
