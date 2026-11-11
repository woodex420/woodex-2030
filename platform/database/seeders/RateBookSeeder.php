<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Sales\Enums\Unit;
use App\Domain\Sales\Models\CatalogItem;
use App\Domain\Sales\Models\EstimatorRate;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * Rate book from real Woodex BOQs (QUO-001): 22 priced catalogue items, the decor item library
 * (rates filled per client) and the 6 website estimator services (WEB-006).
 *
 * Only adds what is missing: rates the owner changed and items the owner hid stay as they are when the seeder runs
 * again (woodex:install, the web installer).
 */
class RateBookSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array{services: list<array<string, mixed>>, catalog: list<array<string, mixed>>} $book */
        $book = json_decode(File::get(database_path('seeders/data/rate-book.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach ($book['services'] as $position => $service) {
            EstimatorRate::query()->firstOrCreate(['key' => $service['key']], [
                'label' => $service['label'],
                'hint' => $service['hint'] ?? null,
                'unit' => Unit::normalize((string) $service['unit'])->value,
                'unit_label' => $service['unitLabel'] ?? 'Area (sq ft)',
                'essential_minor' => Money::ofRupees((int) $service['rates']['essential'])->minor,
                'standard_minor' => Money::ofRupees((int) $service['rates']['standard'])->minor,
                'premium_minor' => Money::ofRupees((int) $service['rates']['premium'])->minor,
                'is_active' => true,
                'position' => $position,
            ]);
        }

        $position = 0;

        foreach ($book['catalog'] as $item) {
            CatalogItem::query()->firstOrCreate(
                ['category' => $item['cat'], 'description' => $item['desc']],
                [
                    'unit' => Unit::normalize((string) $item['unit'])->value,
                    'rate_minor' => Money::ofRupees((int) $item['rate'])->minor,
                    'business_line' => $item['cat'] === 'Furniture' ? 'furniture' : null,
                    'is_active' => true,
                    'position' => $position++,
                ],
            );
        }

        /** @var array<string, list<array{0: string, 1: string}>> $decor */
        $decor = json_decode(File::get(database_path('seeders/data/decor-catalog.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach ($decor as $category => $items) {
            foreach ($items as [$description, $unit]) {
                CatalogItem::query()->firstOrCreate(
                    ['category' => ucfirst($category), 'description' => $description],
                    ['unit' => Unit::normalize($unit)->value, 'rate_minor' => 0, 'is_active' => true, 'position' => $position++],
                );
            }
        }
    }
}
