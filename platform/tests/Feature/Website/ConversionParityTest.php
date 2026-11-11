<?php

use App\Domain\Website\Conversion\ParityCompare;
use App\Domain\Website\Enums\PageType;
use App\Domain\Website\Models\Page;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\InteractsWithPublicWebsite;

/*
| V2-5 (docs/13 §5): the parity gate. The same page, judged by structure and SEO — the tool
| records every difference, the studio decides each one; a group with an unexplained difference
| does not switch. And the conversion status: the switch is file existence, so the state is simply
| which files remain.
*/

uses(InteractsWithPublicWebsite::class);

it('calls two identical renders green', function () {
    $html = <<<'HTML'
        <html><head>
            <title>Interior Design in Lahore · Woodex Interior</title>
            <meta name="description" content="Thoughtful interiors across Lahore.">
            <link rel="canonical" href="https://woodex.com.pk/interior-design/">
            <meta name="robots" content="index,follow">
            <script type="application/ld+json">{"@type":"WebPage"}</script>
        </head><body>
            <section><h1>Interior design</h1></section>
            <section><h2>How we work</h2><p>Text with <a href="/contact/">a link</a>.</p></section>
            <section><h2>Materials</h2><img src="/img/wood.webp" alt="Wood" width="400" height="300"></section>
        </body></html>
        HTML;

    $result = (new ParityCompare)->compare($html, $html, '/interior-design/');

    expect($result['green'])->toBeTrue()
        ->and(collect($result['checks'])->where('pass', false))->toBeEmpty();
});

it('records every difference for the studio to judge, without judging them itself', function () {
    $v1 = <<<'HTML'
        <html><head>
            <title>v1 title</title>
            <meta name="description" content="v1 description">
            <link rel="canonical" href="https://woodex.com.pk/x/">
            <script type="application/ld+json">{"@type":"WebPage"}</script>
        </head><body>
            <section><h1>v1 heading</h1></section>
            <section><h2>One</h2><img src="/img/a.webp" alt="A" width="1" height="1"></section>
            <section><h2>Two</h2><a href="/one/">one</a></section>
        </body></html>
        HTML;

    $v2 = <<<'HTML'
        <html><head>
            <title>v2 title — a recorded improvement</title>
            <meta name="description" content="v2 description">
            <link rel="canonical" href="https://woodex.com.pk/x/">
            <script type="application/ld+json">{"@type":"Article"}</script>
        </head><body>
            <section><h1>v2 heading</h1></section>
            <section><h2>One</h2><img src="/img/a.webp"></section>
            <section><h2>Two</h2><section><h2>Extra</h2></section></section>
        </body></html>
        HTML;

    $result = (new ParityCompare)->compare($v1, $v2, '/x/');

    $failed = collect($result['checks'])->where('pass', false)->pluck('check')->all();

    expect($result['green'])->toBeFalse()
        ->and($failed)->toContain('The h1')
        ->and($failed)->toContain('Title')
        ->and($failed)->toContain('Meta description')
        ->and($failed)->toContain('JSON-LD types')
        ->and($failed)->toContain('Images without alt text')
        ->and($failed)->toContain('Images without width/height')
        ->and($failed)->toContain('Heading order (h2 sequence)')
        // The canonical matched, so it is not in the failed list.
        ->and($failed)->not->toContain('Canonical');
});

it('reports the conversion state per group from file existence', function () {
    // The runtime app's public/ carries the v1 files the repo ships; the command reads them honestly.
    $home = Page::factory()->published()->withSlug('/')->create(['title' => 'Home']);
    Page::factory()->published()->withSlug('/about/')->create(['title' => 'About', 'type' => PageType::About->value]);
    Page::factory()->published()->withSlug('/cms-only-page/')->create(['title' => 'CMS only', 'type' => PageType::Service->value]);

    Artisan::call('woodex:conversion-status');
    $output = Artisan::output();

    expect($output)->toContain('8 · Home')
        ->and($output)->toContain('1 · Legal & utility')
        ->and($output)->toContain('v1 files still there')
        ->and($output)->toContain('still served by v1 files');

    // A --files-root with no files at all reports every page CMS-served.
    $emptyRoot = sys_get_temp_dir().'/conversion-empty-'.uniqid();
    @mkdir($emptyRoot, 0777, true);

    Artisan::call('woodex:conversion-status', ['--files-root' => $emptyRoot]);
    $emptyOutput = Artisan::output();

    expect($emptyOutput)->toContain('CMS serves this group')
        ->and($emptyOutput)->toMatch('/0 of \d+ published pages are still served by v1 files; \d+ by the CMS\./');

    // And with the real public/, the home page's v1 file exists (nothing has switched).
    Artisan::call('woodex:conversion-status');
    $real = Artisan::output();

    $homeRow = collect(explode("\n", $real))->first(fn (string $line): bool => str_contains($line, '8 · Home'));
    expect($homeRow)->not->toBeNull()
        // Woodex OS 1 (§16.19 step 4): the home page is a file now, so the report counts it as file-served.
        ->and(preg_match('/\|\s*1\s*\|\s*1\s*\|\s*0\s*\|\s*files still serve\s*\|/', (string) $homeRow))->toBe(1);
});
