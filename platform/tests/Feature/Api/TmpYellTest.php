<?php

use App\Domain\Commerce\Models\Category;
use Illuminate\Support\Facades\DB;

it('yells the data', function (): void {
    $c = Category::where('slug', 'executive-tables')->first();
    $raw = DB::table('commerce_product_revisions')->first();
    $content = $raw === null ? null : json_decode((string) $raw->content, true);
    $this->report = [
        'driver' => DB::getDriverName(),
        'catId' => $c->id,
        'catIdType' => gettype($c->id),
        'cats' => $content['category_ids'] ?? 'NONE',
        'catTypes' => array_map('gettype', (array) ($content['category_ids'] ?? [])),
        'contentType' => $raw === null ? 'no revs' : gettype($raw->content),
        'contentHead' => $raw === null ? '' : substr((string) $raw->content, 0, 160),
    ];
    throw new RuntimeException('DATA '.json_encode($this->report));
});
