<?php

declare(strict_types=1);

use App\Domain\Website\Builder\BuilderApi;

/*
| Woodex OS 1: the home page is a file like every other page (docs/audit/16.19, step 4). It was exported
| from the page the CMS served, so it is the same page a visitor saw; these tests keep it a complete
| document, free of the two things an export can leak — Blade source and the sandbox's preview beacon.
*/

it('ships the home page as a complete HTML file', function () {
    $path = public_path('index.html');

    expect(is_file($path))->toBeTrue();

    $html = (string) file_get_contents($path);

    expect(str_starts_with(ltrim($html), '<!DOCTYPE html>'))->toBeTrue()
        ->and(stripos($html, '<html'))->not->toBeFalse()
        ->and(stripos($html, '</html>'))->not->toBeFalse()
        ->and(strlen($html))->toBeGreaterThan(20_000);
});

it('carries no Blade source and no sandbox beacon', function () {
    $html = (string) file_get_contents(public_path('index.html'));

    expect($html)->not->toContain('__contextArgs')
        ->not->toContain('<?php')
        ->not->toContain('__preview-beacon')
        ->not->toContain('localhost')
        ->not->toContain('127.0.0.1');
});

it('is the first page the builder offers', function () {
    $pages = BuilderApi::pageList();

    expect($pages[0]['path'])->toBe('index.html')
        ->and($pages[0]['url'])->toBe('/');
});

it('links only to stylesheets and scripts that exist', function () {
    $html = (string) file_get_contents(public_path('index.html'));

    preg_match_all('~<(?:link|script)\b[^>]*(?:href|src)="(/[^"?#]+)~i', $html, $matches);

    $missing = array_values(array_filter($matches[1], static function (string $url): bool {
        $file = public_path(ltrim($url, '/'));

        // Media, theme and tracking URLs are answered by the app, not by a file.
        return ! str_starts_with($url, '/media/')
            && ! str_starts_with($url, '/theme/')
            && ! str_starts_with($url, '/assets/theme.css')
            && ! str_starts_with($url, '/site/tags.js')
            && ! is_file($file);
    }));

    expect($missing)->toBe([]);
});
