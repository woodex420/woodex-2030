<?php

declare(strict_types=1);

use App\Settings\SiteSettings;
use Illuminate\Support\Facades\File;

/*
| The tracking IDs from Website → Settings reach the files through one shared script (docs/09 §11.3, D-69).
|
| The pages are files the web server answers before Laravel runs, so the admin cannot change their HTML. Every
| page loads `/site/tags.js`, and the route answers with the tags that are set — nothing at all until the owner
| pastes an ID, and nothing for signed-in staff, whose visits are not visitors.
*/

function tracking(array $values): void
{
    app(SiteSettings::class)->fill($values)->save();
}

it('links the tags script from every page file, exactly once', function () {
    $pages = collect(File::allFiles(public_path()))
        ->filter(static fn ($file): bool => $file->getFilename() === 'index.html')
        ->values();

    expect($pages)->toHaveCount(142);

    foreach ($pages as $page) {
        $html = (string) file_get_contents($page->getPathname());

        expect(substr_count($html, '<script src="/site/tags.js" defer></script>'), $page->getRelativePathname())
            ->toBe(1);
    }
});

it('answers with nothing until an ID is set', function () {
    $this->get('/site/tags.js')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
        ->assertSee('No tracking is configured', false)
        ->assertDontSee('googletagmanager', false)
        ->assertDontSee('clarity.ms', false);
});

it('loads the Tag Manager container and leaves GA4 to it', function () {
    tracking(['tracking_enabled' => true, 'gtm_id' => 'GTM-TEST123', 'ga4_id' => 'G-TEST123']);

    $this->get('/site/tags.js')
        ->assertOk()
        ->assertSee('GTM-TEST123', false)
        ->assertSee('googletagmanager.com/gtm.js', false)
        ->assertDontSee('gtag/js', false)
        ->assertDontSee('G-TEST123', false);
});

it('loads GA4 directly when there is no container', function () {
    tracking(['tracking_enabled' => true, 'gtm_id' => '', 'ga4_id' => 'G-TEST123']);

    $this->get('/site/tags.js')
        ->assertOk()
        ->assertSee('G-TEST123', false)
        ->assertSee('googletagmanager.com/gtag/js', false)
        ->assertSee("gtag('config', \"G-TEST123\")", false);
});

it('loads Clarity alongside either one', function () {
    tracking(['tracking_enabled' => true, 'ga4_id' => 'G-TEST123', 'clarity_id' => 'clarity123']);

    $this->get('/site/tags.js')
        ->assertOk()
        ->assertSee('clarity.ms/tag/', false)
        ->assertSee('clarity123', false);
});

it('loads nothing while the switch is off', function () {
    tracking(['tracking_enabled' => false, 'gtm_id' => 'GTM-TEST123', 'ga4_id' => 'G-TEST123', 'clarity_id' => 'clarity123']);

    $this->get('/site/tags.js')
        ->assertOk()
        ->assertSee('No tracking is configured', false)
        ->assertDontSee('GTM-TEST123', false);
});

it('loads nothing for signed-in staff', function () {
    tracking(['tracking_enabled' => true, 'ga4_id' => 'G-TEST123']);

    $this->actingAs($this->userWithRole('owner'))
        ->get('/site/tags.js')
        ->assertOk()
        ->assertSee('No tracking is configured', false)
        ->assertDontSee('G-TEST123', false);
});

it('never caches the answer, so a saved ID is live on the next page load', function () {
    $response = $this->get('/site/tags.js');

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});
