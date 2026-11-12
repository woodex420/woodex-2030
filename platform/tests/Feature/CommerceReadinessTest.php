<?php

use App\Domain\Commerce\Models\Product;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

it('reports strict production blockers without mutating records or revealing configuration secrets', function () {
    Storage::fake('local');
    Storage::fake('public');
    config(['app.debug' => true, 'app.url' => 'https://preview.arena.site', 'app.key' => 'private-key-must-not-be-printed']);
    $before = Product::count();
    $code = Artisan::call('commerce:readiness', ['--production' => true]);
    $output = Artisan::output();
    expect($code)->toBe(1)->and($output)->toContain('BLOCKED', 'Mixed image/video gallery', 'Complete Shop/Series management', 'Native product/price/quote cutover', 'No records/files/configuration were changed')
        ->and($output)->not->toContain('private-key-must-not-be-printed')->and(Product::count())->toBe($before);
});
