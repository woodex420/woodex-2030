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

it('keeps text readable in the light theme, with the charcoal sidebar, and in the dark theme', function (string $selector) {
    $light = wxRoles(':root');
    $roles = $selector === ':root' ? $light : array_merge($light, wxRoles($selector));

    $text = [
        ['--wx-text', '--wx-page'], ['--wx-text', '--wx-surface'], ['--wx-text', '--wx-elevated'],
        ['--wx-muted', '--wx-page'], ['--wx-muted', '--wx-surface'], ['--wx-muted', '--wx-elevated'],
        ['--wx-on-primary', '--wx-primary'], ['--wx-on-primary', '--wx-primary-hover'],
        // Icon tiles, and the initials of the signed-in person.
        ['--wx-on-primary-soft', '--wx-primary-soft'],
        ['--wx-sidebar-text', '--wx-sidebar-bg'], ['--wx-sidebar-text', '--wx-sidebar-hover'],
        // A screen's name under the pointer, and the name of the screen one is on (filled).
        ['--wx-sidebar-strong', '--wx-sidebar-bg'], ['--wx-sidebar-strong', '--wx-sidebar-hover'],
        ['--wx-sidebar-on-active', '--wx-sidebar-active'],
        ['--wx-sidebar-muted', '--wx-sidebar-bg'], ['--wx-sidebar-muted', '--wx-sidebar-hover'],
        // The count beside a screen: the sidebar's ground as text on the accent.
        ['--wx-sidebar-bg', '--wx-sidebar-accent'],
        // The line under the brand name.
        ['--wx-sidebar-accent', '--wx-sidebar-bg'],
    ];
    foreach ($text as [$fore, $back]) {
        expect(wxContrast($roles[$fore], $roles[$back]))->toBeGreaterThanOrEqual(4.5, "{$selector}: {$fore} on {$back}");
    }

    $edges = [
        ['--wx-control-border', '--wx-surface'], ['--wx-focus', '--wx-surface'], ['--wx-focus', '--wx-page'],
        ['--wx-sidebar-focus', '--wx-sidebar-bg'], ['--wx-sidebar-accent', '--wx-sidebar-bg'],
        // The screen one is on must stand out. Where it is filled (light and dark), the fill against the sidebar.
        // Where it is only a grey step (the charcoal sidebar), the bar at its edge against that step.
        isset($roles['--wx-sidebar-marker']) && $selector !== ':root.dark'
            ? ['--wx-sidebar-marker', '--wx-sidebar-active']
            : ['--wx-sidebar-active', '--wx-sidebar-bg'],
    ];
    foreach ($edges as [$fore, $back]) {
        expect(wxContrast($roles[$fore], $roles[$back]))->toBeGreaterThanOrEqual(3.0, "{$selector}: {$fore} against {$back}");
    }
})->with([':root', ":root[data-sidebar-tone='charcoal']", ':root.dark']);

it('marks the current screen in every sidebar: a fill, or a bar where the fill is too faint', function () {
    $light = wxRoles(':root');
    $charcoal = array_merge($light, wxRoles(":root[data-sidebar-tone='charcoal']"));

    // Light (the Reimagined admin, DL-017): white, the current screen filled with the green that carries white text.
    expect($light['--wx-sidebar-bg'])->toBe($light['--wx-surface'])
        ->and($light['--wx-sidebar-active'])->toBe($light['--wx-primary'])
        ->and($light)->not->toHaveKey('--wx-sidebar-marker');
    // Charcoal (specification 05): the store's charcoal; its current screen is a grey step, so it has the bar.
    expect($charcoal['--wx-sidebar-bg'])->toBe($light['--wx-charcoal'])
        ->and(wxContrast($charcoal['--wx-sidebar-active'], $charcoal['--wx-sidebar-bg']))->toBeLessThan(3.0)
        ->and($charcoal)->toHaveKey('--wx-sidebar-marker');
    expect(wxAdminCss())->toContain(":root[data-sidebar-tone='charcoal']:not(.dark) .wx-admin .menu-item-active::before");
});

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

it('uses the storefront\'s palette', function () {
    $roles = wxRoles(':root');

    expect($roles['--wx-charcoal'])->toBe('#22262a')
        ->and($roles['--wx-page'])->toBe('#f7f7f7')
        ->and($roles['--wx-surface'])->toBe('#ffffff')
        ->and($roles['--wx-text'])->toBe('#1f1f1f')
        ->and($roles['--wx-muted'])->toBe('#6b6b6b')
        ->and($roles['--wx-border'])->toBe('#e0e0e0')
        ->and($roles['--wx-accent'])->toBe('#629924')
        ->and($roles['--wx-primary'])->toBe('#52801e');
});

it('takes its charcoal, green, text and border colours from the storefront\'s own stylesheet', function () {
    // One global design (decision DL-013). The store declares its colours as HSL triples; the admin as hex.
    // This reads both files, so changing one without the other fails here.
    $storefront = dirname(__DIR__, 3).'/apps/storefront/src/index.css';
    if (! is_file($storefront)) {
        $this->markTestSkipped('The storefront is not beside this application (a packaged deployment).');
    }
    preg_match('/:root \{(.*?)\n  \}/s', file_get_contents($storefront), $root);
    expect($root)->not->toBeEmpty('no :root block in the storefront stylesheet');

    $store = static function (string $name) use ($root): string {
        preg_match('/--'.preg_quote($name, '/').':\s*([\d.]+)\s+([\d.]+)%\s+([\d.]+)%;/', $root[1], $m);
        expect($m)->not->toBeEmpty("the storefront has no --{$name}");
        [$h, $s, $l] = [(float) $m[1] / 360, (float) $m[2] / 100, (float) $m[3] / 100];
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $channel = static function (float $t) use ($p, $q): int {
            $t = $t < 0 ? $t + 1 : ($t > 1 ? $t - 1 : $t);
            $v = match (true) {
                $t < 1 / 6 => $p + ($q - $p) * 6 * $t,
                $t < 1 / 2 => $q,
                $t < 2 / 3 => $p + ($q - $p) * (2 / 3 - $t) * 6,
                default => $p,
            };

            return (int) round($v * 255);
        };

        return sprintf('#%02x%02x%02x', $channel($h + 1 / 3), $channel($h), $channel($h - 1 / 3));
    };

    $roles = wxRoles(':root');
    expect($roles['--wx-accent'])->toBe($store('accent'), 'the green')
        ->and($roles['--wx-charcoal'])->toBe($store('primary'), 'the charcoal')
        ->and($roles['--wx-text'])->toBe($store('foreground'), 'the text colour')
        ->and($roles['--wx-border'])->toBe($store('border'), 'the border colour')
        ->and($roles['--wx-surface'])->toBe($store('background'), 'the surface');
    expect(wxAdminCss())->toContain('--radius-lg: 0.25rem;');
    expect(file_get_contents($storefront))->toContain('--radius: 0.25rem;');
});

it('draws the sign-in page from the admin\'s own roles: no colour of its own', function () {
    // Until 2026-11-17 the sign-in page was a dark glass card on a green gradient, with 30 colours of its own.
    // It is part of the one design now (DL-013): every colour in auth.css is a --wx-* role, so the page follows
    // the light and the dark theme, and the contrast checks above cover it.
    $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/auth.css');
    $rules = preg_replace('#/\*.*?\*/#s', '', $css);

    expect($rules)->not->toMatch('/#[0-9a-fA-F]{3,8}\b/')
        ->and($rules)->not->toContain('backdrop-filter')
        ->and($rules)->not->toContain('gradient')
        ->and($rules)->toContain('var(--wx-primary)')
        ->and($rules)->toContain('var(--wx-surface)');

    $layout = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/layouts/auth.blade.php');
    expect($layout)->not->toContain('auth-glass')->and($layout)->not->toContain('wx-auth-backdrop');
});
