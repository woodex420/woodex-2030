<?php

declare(strict_types=1);

use App\Domain\Website\Support\CanvasHtml;

/*
| The HTML block and the campaign canvas print staff-authored markup raw. The cleaner is the only
| thing between a pasted payload and a visitor, so the bypasses that beat the previous regex are
| regression tests here (Phase 1, audit finding S-03).
*/

it('removes event handlers however the attribute is quoted', function (string $payload) {
    $clean = CanvasHtml::clean($payload);

    expect($clean)->not->toMatch('/\son[a-z]+\s*=/i')
        ->and($clean)->not->toContain('<script');
})->with([
    'double quoted' => '<img src="x" onerror="alert(1)">',
    'single quoted' => "<img src='x' onload='alert(1)'>",
    'unquoted' => '<img src=x onerror=alert(1)>',
    'svg' => '<svg onload=alert(1)><circle r="1"></circle></svg>',
    'body' => '<body onpageshow="alert(1)"><p>Text</p></body>',
]);

it('removes javascript and data URLs after decoding entities', function (string $payload) {
    $clean = CanvasHtml::clean($payload);

    expect($clean)->not->toContain('javascript:')
        ->and($clean)->not->toContain('vbscript:')
        ->and($clean)->not->toContain('data:text/html');
})->with([
    'quoted' => '<a href="javascript:alert(1)">x</a>',
    'unquoted' => '<a href=javascript:alert(1)>x</a>',
    'backticks' => '<a href=`javascript:alert(1)`>x</a>',
    'decimal entity' => '<a href="java&#115;cript:alert(1)">x</a>',
    'hex entity' => '<a href="java&#x73;cript:alert(1)">x</a>',
    'tab' => "<a href=\"java\tscript:alert(1)\">x</a>",
    'data uri' => '<img src="data:text/html;base64,PHNjcmlwdD4=">',
]);

it('removes srcdoc, forms and style imports', function () {
    $clean = CanvasHtml::clean(
        '<iframe srcdoc="<script>alert(1)</script>" src="https://maps.example/embed"></iframe>'.
        '<form action="https://evil.example"><input name="x"></form>'.
        '<style>@import url(//evil.example/x.css); .a { color: red; }</style>'
    );

    expect($clean)->not->toContain('srcdoc')
        ->and($clean)->not->toContain('<form')
        ->and($clean)->not->toContain('<input')
        ->and($clean)->not->toContain('@import')
        ->and($clean)->toContain('iframe src="https://maps.example/embed"')
        ->and($clean)->toContain('color: red');
});

it('keeps the studio\'s legitimate markup intact', function () {
    $html = '<section class="t6 t6-hero"><h1>Spaces</h1><p>Text <strong>bold</strong>.</p>'.
        '<a href="/contact/" class="btn">Start</a>'.
        '<img src="/media/12/room.webp" alt="A room" width="800" height="600" loading="lazy">'.
        '<ul><li>One</li><li>Two</li></ul></section>';

    expect(CanvasHtml::clean($html))->toBe($html);
});

it('adds noopener to links that open a new tab', function () {
    $clean = CanvasHtml::clean('<a href="https://example.com" target="_blank">x</a>');

    expect($clean)->toContain('target="_blank"')
        ->and($clean)->toContain('rel="noopener noreferrer"');
});
