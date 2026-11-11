<?php

declare(strict_types=1);

use App\Domain\Website\Data\LegacyUrlMap;
use App\Domain\Website\Models\Redirect;
use Tests\Concerns\InteractsWithPublicWebsite;

/*
| The parity test of the website (docs/09 §13 step 8): every URL the old site answered still answers, either as a
| page file with its canonical trailing slash or as one 301 to a page that has one.
|
| Since Woodex OS 1 the page **is the file** (docs/audit/16.19). This reads `public/`, which is what the web server
| reads, so a database row with no file can no longer make the contract pass.
*/
uses(InteractsWithPublicWebsite::class);

function legacyFile(string $slug): string
{
    return base_path('public/'.ltrim($slug, '/').'index.html');
}

it('has a file for every page URL of the old site, with its canonical', function () {
    foreach (array_keys(LegacyUrlMap::pages()) as $slug) {
        expect(is_file(legacyFile($slug)), "no file for {$slug}")->toBeTrue();

        expect((string) file_get_contents(legacyFile($slug)))
            ->toContain('<link rel="canonical" href="https://woodex.com.pk'.$slug.'"');
    }

    expect(LegacyUrlMap::pages())->toHaveCount(142);
});

it('sends every redirected URL of the old site to a page that has a file', function () {
    // Empty since 17 Oct 2026: every URL of the old site is a page. The loop is the contract if one comes back.
    expect(LegacyUrlMap::redirects())->toBeArray();

    foreach (LegacyUrlMap::redirects() as $from => $to) {
        expect(is_file(legacyFile($to)), "{$from} points at {$to}, which has no file")->toBeTrue();
    }
});

it('answers a redirect with one 301 and counts the hit', function () {
    Redirect::query()->create(['from_path' => '/old-flat-design/', 'to_path' => '/interior-design/', 'code' => 301, 'hits' => 0]);

    $this->siteGet('/old-flat-design/')
        ->assertStatus(301)
        ->assertRedirect($this->siteUrl('/interior-design/'));

    expect(Redirect::query()->where('from_path', '/old-flat-design/')->value('hits'))->toBe(1);
});

it('redirects the version without the trailing slash of an old page URL', function () {
    $this->siteGet('/renovation')->assertStatus(301)->assertRedirect($this->siteUrl('/renovation/'));
    $this->siteGet('/insights/small-space-ideas')->assertStatus(301)->assertRedirect($this->siteUrl('/insights/small-space-ideas/'));
});
