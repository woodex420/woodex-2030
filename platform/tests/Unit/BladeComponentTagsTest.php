<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/*
| Inside a component tag, Blade compiles no directive.
|
| <x-admin.button wire:click="add(@js($code))"> reaches the browser as written, "@js($code)" and all. The
| attributes of a component are turned into a PHP array before the directives are read, and only @class and
| @style are known there. Nothing fails on the server, and a test that calls the Livewire component never reads
| the attribute. The browser does, and refuses the words: "SyntaxError: Invalid or unexpected token".
|
| The journey in the browser found one on 18 November 2026: the "Add" button of a product in the quotation's
| catalogue search, which added nothing (master list T-086).
|
| What works there: {{ \Illuminate\Support\Js::from($value) }}.
*/

uses(TestCase::class);

it('keeps directives out of component tags, where they are not compiled', function () {
    $found = [];
    $tags = 0;

    foreach ((new Finder)->files()->in(resource_path('views'))->name('*.blade.php')->sortByName() as $file) {
        $source = $file->getContents();
        preg_match_all('/<x-[\w.:\-]+((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/s', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            $tags++;
            // "@click=" and "@keydown.escape=" are attributes of Alpine, not directives of Blade.
            preg_match_all('/(?<![\w@])@(\w+)(?![\w=.:\-])/', $match[1][0], $directives);
            foreach (array_diff($directives[1], ['class', 'style']) as $directive) {
                $found[] = $file->getRelativePathname().':'.(substr_count($source, "\n", 0, $match[0][1]) + 1).' @'.$directive;
            }
        }
    }

    expect($tags)->toBeGreaterThan(1500);
    expect($found)->toBe([]);
});

it('shows why: the directive arrives as written, the echo arrives as words a browser can read', function () {
    // A page gets this from the web middleware; a bare render has to bring it.
    View::share('errors', new ViewErrorBag);
    $code = 'WX-30"×60\' <b>&';

    // The day Blade compiles a directive here, this line fails, and the rule above can go.
    $wrong = Blade::render('<x-admin.button wire:click="add(@js($code))">Add</x-admin.button>', ['code' => $code]);
    expect($wrong)->toContain('add(@js($code))');

    $right = Blade::render('<x-admin.button wire:click="add({{ \Illuminate\Support\Js::from($code) }})">Add</x-admin.button>', ['code' => $code]);
    expect(preg_match('/wire:click="add\(\'([^"\']*)\'\)"/', $right, $said))->toBe(1)
        // Neither kind of quote is left bare, so the attribute and the script's text both end where they should;
        // read as a script reads it, the text is the code again, character for character.
        ->and(json_decode('"'.$said[1].'"', flags: JSON_THROW_ON_ERROR))->toBe($code);
});
