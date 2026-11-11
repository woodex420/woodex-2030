<?php

declare(strict_types=1);

use App\Domain\Website\Seo\FileAudit;
use App\Domain\Website\Seo\SearchMetadataAudit;
use App\Livewire\Admin\Website\SeoOverviewIndex;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    config(['woodex.public_site_url' => 'https://woodex.example']);
    $this->site = sys_get_temp_dir().'/woodex-metadata-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->site);
    $this->app->usePublicPath($this->site);
    Http::fake();
});

afterEach(function () {
    Http::assertNothingSent();
    File::deleteDirectory($this->site);
});

function completeSearchMetadata(string $canonical = 'https://woodex.example/'): string
{
    return '<html><head><title>A complete studio listing in Lahore</title><meta name="description" content="Studio description">'
        .'<link rel="canonical" href="'.$canonical.'"><meta property="og:title" content="Woodex"><meta property="og:description" content="Studio">'
        .'<meta property="og:image" content="https://images.invalid/never-fetched.jpg"></head><body><h1>Woodex</h1></body></html>';
}

it('accepts complete metadata without fetching canonical images or schema URLs', function () {
    $html = str_replace('</head>', '<script type="APPLICATION/LD+JSON; charset=utf-8">{"@context":"https://schema.org","@type":"Organization"}</script></head>', completeSearchMetadata());
    expect(app(SearchMetadataAudit::class)->inspect($html, '/'))->toBe(['canonical' => 'https://woodex.example/', 'issues' => []]);
});

it('flags missing and multiple canonicals social gaps and intentional indexing restrictions for review', function () {
    $html = '<html><head><link rel="alternate CANONICAL" href="/other/"><link rel="canonical" href="https://elsewhere.invalid/">'
        .'<meta name="Googlebot" content="max-snippet:2; NOINDEX"><meta property="og:title" content=" "></head><body></body></html>';
    $result = app(SearchMetadataAudit::class)->inspect($html, '/');
    expect(array_column($result['issues'], 'code'))->toContain('canonical_multiple', 'canonical_absolute', 'canonical_target', 'social_missing', 'indexing_restricted');
    expect(json_encode($result))->toContain('intentional', 'do not remove automatically');
    expect(array_column(app(SearchMetadataAudit::class)->inspect('<html><head></head></html>', '/')['issues'], 'code'))->toContain('canonical_missing');
});

it('distinguishes invalid JSON syntax from empty or scalar structured data', function (string $json, string $code) {
    $html = str_replace('</head>', '<script type="application/ld+json">'.$json.'</script></head>', completeSearchMetadata());
    expect(array_column(app(SearchMetadataAudit::class)->inspect($html, '/')['issues'], 'code'))->toBe([$code]);
})->with([
    ['{"broken":}', 'jsonld_syntax'],
    ['"scalar"', 'jsonld_shape'],
    ['{}', 'jsonld_shape'],
    ['[]', 'jsonld_shape'],
]);

it('inspects files read-only and keeps counts per affected page rather than per finding', function () {
    File::ensureDirectoryExists($this->site.'/complete');
    File::put($this->site.'/index.html', '<html><head><title>Thin</title></head><body></body></html>');
    File::put($this->site.'/complete/index.html', completeSearchMetadata('https://woodex.example/complete/'));
    $before = hash_file('sha256', $this->site.'/index.html');
    $audit = app(SearchMetadataAudit::class)->handle();
    expect($audit['counts'])->toBe(['files' => 2, 'flagged' => 1, 'canonical' => 1, 'social' => 1, 'jsonld' => 0, 'indexing' => 0, 'unreadable' => 0]);
    expect(hash_file('sha256', $this->site.'/index.html'))->toBe($before);
});

it('does not read an oversized file or a symlink outside the public root', function () {
    $outside = $this->site.'-private.html';
    File::put($outside, '<html><head><title>DO-NOT-READ-PRIVATE</title></head></html>');
    symlink($outside, $this->site.'/private.html');
    File::put($this->site.'/huge.html', str_repeat(' ', 2_097_153));
    try {
        $audit = app(SearchMetadataAudit::class)->handle();
        expect(json_encode($audit))->not->toContain('DO-NOT-READ-PRIVATE');
        expect($audit['counts']['files'])->toBe(2);
        foreach ($audit['entries'] as $entry) {
            expect(array_column($entry['issues'], 'code'))->toBe(['unreadable']);
        }
    } finally {
        File::delete($outside);
    }
});

it('keeps both audits available to editors without publishing changes', function () {
    File::put($this->site.'/index.html', '<html><head><title>Woodex</title></head><body><h1>Woodex</h1></body></html>');
    $editor = $this->userWithRole('owner');
    $before = hash_file('sha256', $this->site.'/index.html');
    Livewire::actingAs($editor)->test(SeoOverviewIndex::class)
        ->assertSee('Search listings')->assertSee('Canonical &amp; social', false)
        ->call('setTab', 'metadata')->assertSee('No nonempty canonical')->assertSee('JSON-LD checks syntax only.')
        ->call('setTab', 'not-a-tab')->assertSet('tab', 'listings')->assertSee('No description');
    expect(hash_file('sha256', $this->site.'/index.html'))->toBe($before);
});

it('rejects a user without website permission', function () {
    $support = $this->userWithRole('support');
    Livewire::actingAs($support)->test(SeoOverviewIndex::class)->assertForbidden();
});

it('filters metadata by category text and review state and clears filters atomically', function () {
    File::put($this->site.'/index.html', completeSearchMetadata());
    foreach (['canonical', 'indexing', 'schema'] as $slug) {
        File::ensureDirectoryExists($this->site.'/'.$slug);
        $html = completeSearchMetadata('https://woodex.example/'.$slug.'/');
        $html = match ($slug) {
            'canonical' => str_replace('https://woodex.example/canonical/', 'https://woodex.example/different/', $html),
            'indexing' => str_replace('</head>', '<meta name="robots" content="noindex"></head>', $html),
            default => str_replace('</head>', '<script type="application/ld+json">{broken}</script></head>', $html),
        };
        File::put($this->site.'/'.$slug.'/index.html', $html);
    }
    $editor = $this->userWithRole('owner');
    Livewire::actingAs($editor)->test(SeoOverviewIndex::class)->call('setTab', 'metadata')
        ->assertViewHas('metadataRows', fn (array $rows): bool => count($rows) === 3)
        ->call('setMetadataGroup', 'indexing')->assertViewHas('metadataRows', fn (array $rows): bool => count($rows) === 1 && $rows[0]['url'] === '/indexing/')
        ->set('search', 'SCHEMA')->assertSee('No pages match these review filters.')
        ->call('clearMetadataFilters')->assertSet('search', '')->assertSet('metadataGroup', 'all')
        ->call('setFilter', 'all')->assertViewHas('metadataRows', fn (array $rows): bool => count($rows) === 4)
        ->call('setMetadataGroup', 'invalid')->assertSet('metadataGroup', 'all')
        ->set('search', 'jsonld_syntax')->assertViewHas('metadataRows', fn (array $rows): bool => count($rows) === 1 && $rows[0]['url'] === '/schema/');
});

it('does not rerun the listing and link audit on metadata interactions', function () {
    File::put($this->site.'/index.html', completeSearchMetadata());
    $component = Livewire::actingAs($this->userWithRole('owner'))->test(SeoOverviewIndex::class)->call('setTab', 'metadata');
    $this->app->bind(FileAudit::class, fn () => throw new RuntimeException('Listing audit must not run on metadata interactions.'));
    $component->call('setMetadataGroup', 'canonical')->assertOk();
});

it('rechecks website permission before reading files after access is revoked', function () {
    $editor = User::factory()->create();
    $editor->givePermissionTo('website.edit');
    $component = Livewire::actingAs($editor)->test(SeoOverviewIndex::class);
    $editor->revokePermissionTo('website.edit');
    $editor->unsetRelation('permissions');
    $component->call('setTab', 'metadata')->assertForbidden();
});

it('opens cited metadata audit links directly and normalizes invalid URL controls', function () {
    File::put($this->site.'/index.html', completeSearchMetadata());
    $owner = $this->userWithRole('owner');
    Livewire::actingAs($owner)->withQueryParams(['audit' => 'metadata', 'finding' => 'canonical'])->test(SeoOverviewIndex::class)
        ->assertSet('tab', 'metadata')->assertSet('metadataGroup', 'canonical')->assertSee('Finding category');
    Livewire::actingAs($owner)->withQueryParams(['audit' => 'invalid', 'finding' => 'invalid'])->test(SeoOverviewIndex::class)
        ->assertSet('tab', 'listings')->assertSet('metadataGroup', 'all');
});
