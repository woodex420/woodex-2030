<?php

/*
| The admin's colours, checked for contrast (WCAG 2.1). The owner's specification gives the palette
| "subject to contrast validation" (docs/woodex-os/spec/05-ADMIN-IA-AND-UX.md §5); this is that validation,
| kept as a test so that a later change of a token cannot quietly make text unreadable.
|
| 4.5:1 for text. 3:1 for the edges of controls and for the focus outline.
*/

function wxAdminCss(): string
{
    return file_get_contents(dirname(__DIR__, 2).'/resources/css/admin.css');
}

/** @return array<string, string> the --wx-* roles of one theme block */
function wxRoles(string $selector): array
{
    preg_match('/^'.preg_quote($selector, '/').' \{\n(.*?)\n\}/ms', wxAdminCss(), $block);
    expect($block)->not->toBeEmpty("no {$selector} block in admin.css");
    preg_match_all('/^\s*(--[a-z0-9-]+):\s*(#[0-9a-f]{6});/m', $block[1], $pairs);

    return array_combine($pairs[1], $pairs[2]);
}

function wxScale(string $name): string
{
    preg_match('/^\s*--color-'.preg_quote($name, '/').':\s*(#[0-9a-f]{6});/m', wxAdminCss(), $match);
    expect($match)->not->toBeEmpty("no --color-{$name} in admin.css");

    return $match[1];
}

function wxContrast(string $a, string $b): float
{
    $luminance = static function (string $hex): float {
        $channels = array_map(static fn (string $pair): float => hexdec($pair) / 255, str_split(ltrim($hex, '#'), 2));
        $linear = array_map(static fn (float $c): float => $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, $channels);

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    };
    [$hi, $lo] = [max($luminance($a), $luminance($b)), min($luminance($a), $luminance($b))];

    return ($hi + 0.05) / ($lo + 0.05);
}

it('computes contrast the way WCAG defines it', function () {
    expect(round(wxContrast('#000000', '#ffffff'), 2))->toBe(21.0)
        ->and(round(wxContrast('#ffffff', '#4f9d21'), 2))->toBe(3.4)   // why the logo green carries no white text
        ->and(round(wxContrast('#777777', '#ffffff'), 2))->toBe(4.48); // the classic near miss
});

it('keeps text readable in the light theme and in the dark one', function (string $selector) {
    $light = wxRoles(':root');
    $roles = $selector === ':root' ? $light : array_merge($light, wxRoles($selector));

    $text = [
        ['--wx-text', '--wx-page'], ['--wx-text', '--wx-surface'], ['--wx-text', '--wx-elevated'],
        ['--wx-muted', '--wx-page'], ['--wx-muted', '--wx-surface'], ['--wx-muted', '--wx-elevated'],
        ['--wx-on-primary', '--wx-primary'], ['--wx-on-primary', '--wx-primary-hover'],
        ['--wx-sidebar-text', '--wx-sidebar-bg'], ['--wx-sidebar-text', '--wx-sidebar-hover'],
        ['--wx-sidebar-strong', '--wx-sidebar-active'], ['--wx-sidebar-muted', '--wx-sidebar-bg'],
        ['--wx-sidebar-muted', '--wx-sidebar-hover'], ['--wx-sidebar-bg', '--wx-sidebar-accent'],
    ];
    foreach ($text as [$fore, $back]) {
        expect(wxContrast($roles[$fore], $roles[$back]))->toBeGreaterThanOrEqual(4.5, "{$selector}: {$fore} on {$back}");
    }

    $edges = [
        ['--wx-control-border', '--wx-surface'], ['--wx-focus', '--wx-surface'], ['--wx-focus', '--wx-page'],
        ['--wx-sidebar-focus', '--wx-sidebar-bg'], ['--wx-sidebar-accent', '--wx-sidebar-bg'], ['--wx-sidebar-accent', '--wx-sidebar-active'],
    ];
    foreach ($edges as [$fore, $back]) {
        expect(wxContrast($roles[$fore], $roles[$back]))->toBeGreaterThanOrEqual(3.0, "{$selector}: {$fore} against {$back}");
    }
})->with([':root', ':root.dark']);

it('keeps the scale steps that carry text readable', function () {
    $white = '#ffffff';
    // White text on a filled control or badge.
    foreach (['brand-500', 'brand-600', 'success-600', 'error-600', 'info-600', 'warning-600'] as $fill) {
        expect(wxContrast($white, wxScale($fill)))->toBeGreaterThanOrEqual(4.5, "white on {$fill}");
    }
    // Coloured text on white, and on its own tint (badges).
    foreach (['brand-500', 'gray-400', 'gray-500', 'gray-600'] as $ink) {
        expect(wxContrast(wxScale($ink), $white))->toBeGreaterThanOrEqual(4.5, "{$ink} on white");
    }
    foreach (['success', 'error', 'warning', 'info'] as $status) {
        expect(wxContrast(wxScale("{$status}-700"), wxScale("{$status}-50")))->toBeGreaterThanOrEqual(4.5, "{$status}-700 on {$status}-50");
    }
});

it('uses the palette of the Agency OS look', function () {
    $roles = wxRoles(':root');

    expect($roles['--wx-sidebar-bg'])->toBe('#171b1e')
        ->and($roles['--wx-page'])->toBe('#f6f7f8')
        ->and($roles['--wx-surface'])->toBe('#ffffff')
        ->and($roles['--wx-text'])->toBe('#1d2226')
        ->and($roles['--wx-muted'])->toBe('#66717a')
        ->and($roles['--wx-border'])->toBe('#e5e8ea')
        ->and($roles['--wx-accent'])->toBe('#4f9d21')
        ->and($roles['--wx-primary'])->toBe('#43851c');
});
