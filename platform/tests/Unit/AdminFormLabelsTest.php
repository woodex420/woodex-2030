<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/*
| Every field of a form has a name a screen reader can say.
|
| x-admin.field gives the caption. With `for`, the caption is the label of the control that carries that id.
| Without `for` the field is a named group, which is right for a set of controls (checkboxes, a type and an
| amount) and wrong for a single one: the input itself would have no name. A browser that opened every screen
| (scripts/os-screens.mjs, 2026-11-16) found 30 such inputs on 8 screens.
*/

uses(TestCase::class);

it('names a single control through its field: for= on the field, the same id on the control', function () {
    $unnamed = [];
    $fields = 0;

    foreach ((new Finder)->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
        $source = $file->getContents();
        preg_match_all('/<x-admin\.field\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>(.*?)<\/x-admin\.field>/s', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            $fields++;
            [$attributes, $body] = [$match[1][0], $match[2][0]];
            if (preg_match('/(?:^|\s):?for=/', $attributes) === 1) {
                continue;
            }

            // A group: several controls, or controls that bring their own <label>. One bare control is not a group.
            $controls = preg_match_all('/<(?:input|select|textarea)\b/', $body);
            if ($controls === 1 && ! str_contains($body, '<label') && ! str_contains($body, 'aria-label')) {
                $line = substr_count($source, "\n", 0, $match[0][1]) + 1;
                $unnamed[] = $file->getRelativePathname().':'.$line;
            }
        }
    }

    expect($fields)->toBeGreaterThan(200);
    expect($unnamed)->toBe([]);
});

it('renders a field with for= as a label, and a field without it as a named group', function () {
    // A page gets this from the web middleware; a bare render has to bring it.
    View::share('errors', new ViewErrorBag);

    $single = Blade::render('<x-admin.field label="Company" for="company"><input id="company"></x-admin.field>');
    expect($single)->toContain('<label class="wx-label" for="company">Company</label>')
        ->and($single)->not->toContain('role="group"');

    $group = Blade::render('<x-admin.field label="Channels"><label><input type="checkbox"> Email</label><label><input type="checkbox"> Telegram</label></x-admin.field>');
    expect($group)->toContain('role="group" aria-label="Channels"')
        ->and($group)->toContain('<span class="wx-label" aria-hidden="true">Channels</span>')
        ->and($group)->not->toContain('for=');
});
