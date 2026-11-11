<?php

use App\Domain\Crm\Models\PipelineStage;
use App\Domain\Sales\Models\CatalogItem;
use App\Domain\Sales\Models\EstimatorRate;
use App\Domain\Sales\Models\QuoteTemplate;
use App\Settings\BusinessSettings;
use App\Settings\SalesSettings;
use Database\Seeders\ProductionSeeder;
use Spatie\Permission\Models\Role;

it('seeds the Woodex reference data', function () {
    expect(PipelineStage::query()->count())->toBe(8)
        ->and(Role::query()->pluck('name')->sort()->values()->all())
        ->toBe(['accountant', 'admin', 'designer_pm', 'editor', 'owner', 'sales', 'sales_manager', 'support'])
        ->and(QuoteTemplate::query()->count())->toBe(16)
        ->and(EstimatorRate::query()->count())->toBe(6)
        ->and(CatalogItem::query()->where('rate_minor', '>', 0)->count())->toBe(22);

    $renovation = QuoteTemplate::query()->where('slug', 'renovation')->sole();
    expect($renovation->sectionCount())->toBe(10)
        ->and($renovation->milestone_plan->value)->toBe('execution_50_40_10');

    expect(QuoteTemplate::query()->where('slug', 'interior-designing')->sole()->milestone_plan->value)->toBe('design_75_25')
        ->and(EstimatorRate::query()->where('key', 'fit-out')->sole()->standard_minor)->toBe(420_000);
});

it('is idempotent', function () {
    $this->seed(ProductionSeeder::class);

    expect(QuoteTemplate::query()->count())->toBe(16)
        ->and(PipelineStage::query()->count())->toBe(8);
});

it('ships business and sales settings defaults', function () {
    $business = app(BusinessSettings::class);
    $sales = app(SalesSettings::class);

    expect($business->whatsapp)->toBe('+923224000768')
        ->and($business->hours()->opensAt)->toBe('10:00')
        ->and($sales->quote_validity_days)->toBe(15)
        ->and($sales->signatories[0]['name'])->toBe('Imtiaz Ahmad');
});
