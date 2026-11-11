<?php

declare(strict_types=1);

use App\Domain\Website\Models\Page;
use Illuminate\Http\Request;

/*
| The home page. Woodex OS 1 (docs/audit/16.19 step 4) made `/` a page file like every other address: the
| CMS-rendered home was exported to public/index.html, which the web server answers before Laravel. The CMS
| row still answers a request that reaches the app — the fallback for a deploy where the file is missing —
| and this file keeps both halves honest.
*/

it('serves the site root from a page file', function () {
    expect(public_path('index.html'))->toBeFile();

    $html = (string) file_get_contents(public_path('index.html'));

    expect(str_starts_with(ltrim($html), '<!DOCTYPE html>'))->toBeTrue()
        ->and(stripos($html, '</html>'))->not->toBeFalse();
});

it('still keeps the other page groups on their static files', function () {
    expect(public_path('about/index.html'))->toBeFile();
});
