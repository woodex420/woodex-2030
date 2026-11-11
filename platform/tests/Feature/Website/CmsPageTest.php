<?php

use App\Domain\Website\Enums\PageStatus;
use App\Domain\Website\Enums\PageType;
use App\Domain\Website\Models\Page;
use App\Domain\Website\Models\Redirect;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\File;
use Tests\Concerns\InteractsWithPublicWebsite;

uses(InteractsWithPublicWebsite::class);

/*
| A published page from the CMS answers at its own URL (docs/09 §2, D-67): the legacy URLs keep their promise
| through one 301 each, only one canonical form exists per page, and a draft is invisible to visitors.
*/
it('redirects the version without a trailing slash to the canonical one', function () {
    Page::factory()->published()->withSlug('/about/')->create(['title' => 'About the studio']);

    $this->siteGet('/about')->assertRedirect($this->siteUrl('/about/'))->assertStatus(301);
    $this->siteGet('/about?utm=1')->assertRedirect($this->siteUrl('/about/').'?utm=1')->assertStatus(301);

    // The same redirect through the HTTP kernel: the 404 handler reaches the site router.
    $this->get('/about')->assertRedirect($this->siteUrl('/about/'))->assertStatus(301);
});

it('sends an old URL to its new page with one 301 and counts the hit', function () {
    Page::factory()->published()->withSlug('/interior-design/')->create(['title' => 'Interior Design']);
    // A URL of its own: the 54 redirects of the old site are seeded by a migration and have their own test.
    $redirect = Redirect::factory()->create([
        'from_path' => '/old-flat-design/',
        'to_path' => '/interior-design/',
        'code' => 301,
    ]);

    $this->siteGet('/old-flat-design/')
        ->assertRedirect($this->siteUrl('/interior-design/'))
        ->assertStatus(301);

    expect($redirect->fresh()->hits)->toBe(1);

    $this->siteGet('/old-flat-design/')->assertStatus(301);
    expect($redirect->fresh()->hits)->toBe(2);
});

it('lists only the files the website serves, never a row that would answer 404', function () {
    // Since OS 1 the site is the files, so the sitemap is built from the files: built from the rows it listed 57
    // of 143 pages and was blind to the service, city, area and project pages (20 Oct).
    $site = sys_get_temp_dir().'/woodex-sitemap-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($site.'/about');
    File::ensureDirectoryExists($site.'/draft-only');
    File::put($site.'/index.html', '<html><head><title>Home</title></head><body></body></html>');
    File::put($site.'/about/index.html', '<html><head><title>About</title></head><body></body></html>');
    File::put($site.'/draft-only/index.html', '<html><head><meta name="robots" content="noindex"></head><body></body></html>');
    $this->app->usePublicPath($site);

    Page::factory()->published()->withSlug('/cms-only/')->create(['title' => 'CMS only']);
    Page::factory()->withSlug('/still-draft/')->create(['title' => 'Draft']);
    Page::factory()->published()->withSlug('/about/')->create(['title' => 'A row shadowed by its file']);

    $xml = simplexml_load_string($this->get('/sitemap.xml')->assertOk()->getContent());

    $urls = [];
    foreach ($xml->url as $url) {
        $urls[] = (string) $url->loc;
    }

    // A published row with no file is a 404 since the renderer was retired, so the sitemap cannot advertise it.
    $base = rtrim((string) config('woodex.public_site_url'), '/');

    expect($urls)->toBe([$base.'/', $base.'/about/']);

    $this->siteGet('/cms-only/')->assertNotFound();

    File::deleteDirectory($site);
});

it('answers every host with the same canonical sitemap', function () {
    // The URLs are canonical (the public site), so asking through the admin domain must not put the admin host in
    // the sitemap, and a document cached for one host must never hand back a different one.
    $site = sys_get_temp_dir().'/woodex-sitemap-hosts-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($site);
    File::put($site.'/index.html', '<html><head><title>Home</title></head><body></body></html>');
    $this->app->usePublicPath($site);

    $base = rtrim((string) config('woodex.public_site_url'), '/');

    $public = $this->get('https://woodex.com.pk/sitemap.xml')->assertOk()->getContent();
    $admin = $this->get('https://admin.woodex.com.pk/sitemap.xml')->assertOk()->getContent();

    expect($public)->toBe($admin)
        ->and($public)->toContain('<loc>'.$base.'/</loc>')
        ->and($public)->not->toContain('admin.woodex.com.pk');

    File::deleteDirectory($site);
});

it('keeps page slugs unique and always stores them with a trailing slash', function () {
    Page::factory()->withSlug('/interior-design')->create();

    expect(Page::first()->slug)->toBe('/interior-design/')
        ->and(fn () => Page::factory()->withSlug('/interior-design/')->create())->toThrow(QueryException::class);
});

it('does not answer for the app, only for the website', function () {
    Page::factory()->published()->withSlug('/install/')->create(['title' => 'Not the installer']);
    Page::factory()->published()->withSlug('/admin/')->create(['title' => 'Not the admin']);

    // Those first segments belong to the app: a page may never take them over.
    $this->siteGet('/install/')->assertNotFound();
    $this->siteGet('/admin/')->assertNotFound();
});

it('stores the page status and type as enums', function () {
    $page = Page::factory()->published()->create();

    expect($page->status)->toBe(PageStatus::Published)
        ->and($page->isPublished())->toBeTrue()
        ->and($page->type)->toBeInstanceOf(PageType::class);
});
