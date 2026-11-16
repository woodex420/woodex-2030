<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Reference data everywhere; demo data only on a developer machine.
     */
    public function run(): void
    {
        $this->call(ProductionSeeder::class);

        // DemoSeeder builds Interior-flavoured demonstration records. It is no longer run automatically in a local
        // Furniture environment; a synthetic preview is seeded by tools/preview instead.
    }
}
