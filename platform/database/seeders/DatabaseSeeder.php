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

        if (app()->isLocal()) {
            $this->call(DemoSeeder::class);
        }
    }
}
