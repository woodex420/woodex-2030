<?php

use App\Domain\Website\Enums\PageStatus;
use App\Domain\Website\Enums\PageType;
use App\Domain\Website\Models\Page;
use App\Domain\Website\Models\Redirect;
use App\Domain\Website\Services\SiteRouter;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
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

it('gives no file a page\'s address: no trailing slash is offered, and it is not found at once', function (string $address) {
    // A page is a folder, so no part of a page's address has a dot. An address with one names a file. Until
    // 21 November 2026 each of these was first sent to itself with a slash (301) and only then not found: the
    // check that asks the live site from outside takes that for a fault on a settings file, and it was one
    // (decision DL-045).
    expect(app(SiteRouter::class)->respond(Request::create($address, 'GET')))->toBeNull();

    // The same through the HTTP kernel, where the fallback route asks the router, and with ?preview=1, which
    // has a redirect of its own for a page.
    foreach ([$address, $address.'?preview=1'] as $asked) {
        $response = $this->get($asked);

        expect($response->getStatusCode())->toBe(404)
            ->and($response->headers->has('Location'))->toBeFalse();
    }
})->with([
    'the settings file' => '/.env',
    'its example' => '/.env.example',
    'the history' => '/.git/config',
    'the web server\'s rules' => '/.htaccess',
    'the rules of the uploads folder' => '/assets/uploads/.htaccess',
    'the list of packages' => '/composer.json',
    'a file of the configuration' => '/config/app.php',
    'the demo\'s database' => '/database/woodex-synthetic-vercel-demo.sqlite',
    'the key of the demo\'s database' => '/database/woodex-synthetic-vercel-demo.sqlite.key',
    'a build script' => '/tools/vercel/build-demo.php',
    'the front controller, by its folder' => '/public/index.php',
    'a climb out of a folder' => '/build/../index.php',
    'another product\'s sign-in' => '/wp-login.php',
    'a well-known file nobody put there' => '/.well-known/security.txt',
]);

it('still sends an address that could be a page to its form with a slash', function (string $address) {
    $response = app(SiteRouter::class)->respond(Request::create($address, 'GET'));

    expect($response?->getStatusCode())->toBe(301)
        ->and((string) $response?->headers->get('Location'))->toEndWith($address.'/');
})->with(['/about', '/insights/small-space-ideas', '/About_Us', '/artisan', '/database']);

it('honours a redirect entered for an old address that was a file, in one step', function () {
    $redirect = Redirect::factory()->create(['from_path' => '/services.html/', 'to_path' => '/interior-design/', 'code' => 301]);

    // Asked as the old site linked it, without a slash: one 301 to the new page, not one to "/services.html/" first.
    $this->siteGet('/services.html')->assertRedirect($this->siteUrl('/interior-design/'))->assertStatus(301);
    $this->siteGet('/services.html/')->assertRedirect($this->siteUrl('/interior-design/'))->assertStatus(301);

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
