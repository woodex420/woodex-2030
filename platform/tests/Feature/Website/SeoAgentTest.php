<?php

use App\Domain\Website\Models\Redirect;
use App\Domain\Website\Seo\FileAudit;
use App\Livewire\Admin\Website\SeoAgentIndex;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

/*
| The SEO agent, read from the pages themselves (Woodex OS 1, step 6). Every finding is a plain rule a person
| can repeat: a title, a description, one H1, alt text, enough words, and every internal link answering. The fix
| happens in the builder, on the file — the screen points at it.
*/

beforeEach(function () {
    $this->editor = User::factory()->create();
    $this->editor->givePermissionTo('website.edit');

    $this->site = sys_get_temp_dir().'/woodex-seo-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->site);
    $this->app->usePublicPath($this->site);
});

afterEach(function () {
    File::deleteDirectory($this->site);
});

/** A page with a complete listing and enough words; $overrides replace individual parts. */
function seoPage(string $title = 'A complete, well described page for the studio', string $description = 'One studio in Lahore: design, drawings, joinery and handing over a finished room to the family that asked for it.'): string
{
    return '<html><head><title>'.e($title).'</title><meta name="description" content="'.e($description).'"></head>'
        .'<body><main><h1>'.e($title).'</h1><p>'.str_repeat('word ', 150).'</p></main></body></html>';
}

it('lists every finding against the file that has it, and points at the builder', function () {
    File::ensureDirectoryExists($this->site.'/missing');
    File::ensureDirectoryExists($this->site.'/complete');
    // Both are linked from the home page, so the orphan rule does not name them.
    File::put($this->site.'/index.html', '<html><head><title>The home page of the Woodex studio</title>'
        .'<meta name="description" content="Interior design in Lahore by one studio: drawings, joinery, site work and a finished handover."></head>'
        .'<body><main><h1>Home</h1><a href="/missing/">Missing</a><a href="/complete/">Complete</a><p>'.str_repeat('word ', 150).'</p></main></body></html>');
    File::put($this->site.'/missing/index.html', '<html><head><title>Thin</title></head><body><main><h1>Thin</h1><p>Tiny.</p></main></body></html>');
    File::put($this->site.'/complete/index.html', seoPage());

    Livewire::actingAs($this->editor)
        ->test(SeoAgentIndex::class)
        ->assertSee('/missing/')
        ->assertSee('No meta description')
        ->assertSee('Title is only 4 characters')
        ->assertSee('Open in builder')
        ->assertDontSee('/complete/');

    // The rule filter narrows the worklist to one rule.
    Livewire::actingAs($this->editor)
        ->test(SeoAgentIndex::class)
        ->call('setCode', 'missing_description')
        ->assertSee('/missing/')
        ->assertDontSee('Title is only 4 characters');
});

it('counts a content image without alt text, but not the decorative brand mark', function () {
    File::ensureDirectoryExists($this->site.'/hero');
    File::ensureDirectoryExists($this->site.'/plain');
    File::put($this->site.'/hero/index.html', '<html><head><title>A page with an undescribed hero image</title></head><body>'
        .'<header><img src="/assets/img/logo.webp" alt=""></header>'
        .'<main><h1>Hero</h1><p>'.str_repeat('word ', 150).'</p><img src="/assets/img/hero.webp" alt=""></main>'
        .'<footer><img src="/assets/img/mark.webp" alt=""></footer></body></html>');
    File::put($this->site.'/plain/index.html', '<html><head><title>A page whose only empty alt is the logo</title></head><body>'
        .'<header><img src="/assets/img/logo.webp" alt=""></header><main><h1>Plain</h1><p>'.str_repeat('word ', 150).'</p></main></body></html>');

    $audit = (new FileAudit)->handle();

    $byUrl = collect($audit['pages'])->keyBy('url');
    $plainCodes = collect($byUrl->get('/plain/')['issues'] ?? [])->pluck('code')->all();

    expect($audit['counts']['images_without_alt'])->toBe(1)
        ->and($byUrl->has('/hero/'))->toBeTrue()
        ->and($plainCodes)->not->toContain('missing_alt');
});

it('checks every internal link, orphan page and redirect against the files', function () {
    File::ensureDirectoryExists($this->site.'/about');
    File::ensureDirectoryExists($this->site.'/alone');
    File::put($this->site.'/index.html', '<html><head><title>The home page of the Woodex studio</title>'
        .'<meta name="description" content="Interior design in Lahore by one studio: drawings, joinery, site work and a finished handover."></head>'
        .'<body><main><h1>Home</h1><a href="/about/">About</a><a href="/nowhere/">Dead</a><p>'.str_repeat('word ', 150).'</p></main></body></html>');
    File::put($this->site.'/about/index.html', '<html><head><title>About the Woodex studio in Lahore</title>'
        .'<meta name="description" content="The people, the workshop and the way a project runs from the first visit to the last coat of paint."></head>'
        .'<body><main><h1>About</h1><p>'.str_repeat('word ', 150).'</p></main></body></html>');
    File::put($this->site.'/alone/index.html', '<html><head><title>A page nothing links to at all</title>'
        .'<meta name="description" content="A page that exists and answers, but no other page on the site points a visitor at it."></head>'
        .'<body><main><h1>Alone</h1><p>'.str_repeat('word ', 150).'</p></main></body></html>');

    Redirect::query()->create(['from_path' => '/old-page/', 'to_path' => '/does-not-exist/', 'code' => 301, 'note' => null]);

    $audit = (new FileAudit)->handle();
    $site = collect($audit['site'])->keyBy('code');

    expect($audit['counts']['broken_links'])->toBe(1)
        ->and($site->get('broken_links')['message'])->toContain('/nowhere/')
        ->and($site->get('orphan_pages')['message'])->toContain('/alone/')
        ->and($site->get('broken_redirects')['message'])->toContain('/old-page/');
});

it('catches a listing a search engine cuts off, and a page that asks not to be indexed', function () {
    File::ensureDirectoryExists($this->site.'/long');
    File::ensureDirectoryExists($this->site.'/hidden');
    File::put($this->site.'/long/index.html', seoPage(
        str_repeat('A very long title for a page that Google will cut off in the results ', 2),
        str_repeat('A description far longer than a search result can show, written without a full stop ', 3),
    ));
    File::put($this->site.'/hidden/index.html', '<html><head><title>A page kept out of search on purpose</title>'
        .'<meta name="robots" content="noindex"><meta name="description" content="Out of the index on purpose, for a campaign or a test."></head>'
        .'<body><main><h1>Hidden</h1><p>'.str_repeat('word ', 150).'</p></main></body></html>');

    $audit = (new FileAudit)->handle();
    $codes = collect($audit['pages'])->flatMap(fn (array $page): array => collect($page['issues'])->pluck('code')->all());

    expect($codes)->toContain('long_title')->toContain('long_description')->toContain('noindex');
});

it('keeps the SEO screens behind the website permission', function () {
    $intruder = User::factory()->create();

    Livewire::actingAs($intruder)->test(SeoAgentIndex::class)->assertStatus(403);
});
