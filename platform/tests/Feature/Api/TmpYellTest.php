<?php

use App\Domain\Commerce\Models\Category;
use Illuminate\Support\Facades\DB;

it('yells the data', function (): void {
    $c = Category::where('slug', 'executive-tables')->first();
    $raw = DB::table('commerce_product_revisions')->latest('id')->first();
    $content = $raw === null ? null : json_decode((string) $raw->content, true);
    $cats = $content['category_ids'] ?? 'NONE';
    $bits = 'D_'.DB::getDriverName().'_cat'.($c->id ?? 'x').'t'.gettype($c->id)
        .'_cats'.implode('-', (array) $cats).'t'.implode('-', array_map('gettype', (array) $cats))
        .'_json'.substr(md5((string) ($raw->content ?? '')), 0, 4);
    // A class name is printed where the message is not: it reaches the annotation.
    throw new \RuntimeException($bits);
});
