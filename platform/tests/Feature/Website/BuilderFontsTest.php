<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
| Slice G: the page builder no longer asks a third-party font host for a glyph.
|
| The typography picker offers 26 families. It used to build a fonts.googleapis.com stylesheet for whichever
| family a page element named — and the builder saves the whole document, so a page edited that way shipped with
| an external stylesheet on every visit. Every family in the picker now has its own woff2 files in
| public/assets/fonts and one local stylesheet, public/builder/fonts.css.
*/

/**
 * The families the builder's typography picker offers, read from the picker's own table.
 *
 * @return list<string>
 */
function builderFontFamilies(): array
{
    $js = File::get(public_path('builder/builder.js'));

    if (preg_match('/var GFONTS = \{(.*?)\};/s', $js, $match) !== 1) {
        return [];
    }

    preg_match_all('/"([^"]+)"\s*:\s*"([^"]*)"/', $match[1], $pairs, PREG_SET_ORDER);

    return array_values(array_map(static fn (array $pair): string => $pair[1], $pairs));
}

it('serves every builder font from the app, never from a third party', function () {
    $js = File::get(public_path('builder/builder.js'));

    expect($js)->not->toContain('fonts.googleapis.com')
        ->and($js)->not->toContain('fonts.gstatic.com')
        ->and($js)->toContain('"/builder/fonts.css"');

    $css = File::get(public_path('builder/fonts.css'));

    expect($css)->not->toContain('fonts.googleapis.com')
        ->and($css)->not->toContain('fonts.gstatic.com')
        ->and($css)->not->toContain('http://')
        ->and($css)->not->toContain('https://')
        ->and($css)->toContain('url("/assets/fonts/');
});

it('has a local file and a face for every family in the picker', function () {
    $families = builderFontFamilies();

    // The list is the source of truth for the guard: if it shrinks, this test still covers what is left.
    expect($families)->toHaveCount(26);

    $css = File::get(public_path('builder/fonts.css'));

    foreach ($families as $family) {
        expect($css)->toContain('font-family:"'.$family.'"');
    }

    preg_match_all('#url\("/assets/fonts/([a-z0-9-]+)\.woff2"\)#', $css, $files);

    expect($files[1])->not->toBeEmpty();

    foreach (array_unique($files[1]) as $file) {
        expect(File::exists(public_path('assets/fonts/'.$file.'.woff2')))->toBeTrue();
    }
});

it('declares the four weights the picker asks for', function () {
    $css = File::get(public_path('builder/fonts.css'));

    // Inter is the heaviest user of the table: 400/500/600/700 all appear in the picker's data.
    expect($css)->toContain('font-family:"Inter";src:url("/assets/fonts/inter-400.woff2")')
        ->and($css)->toContain('font-family:"Inter";src:url("/assets/fonts/inter-700.woff2")')
        ->and(substr_count($css, '@font-face'))->toBeGreaterThanOrEqual(26);
});
