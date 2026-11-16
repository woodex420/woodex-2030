<?php

declare(strict_types=1);

use App\Domain\Website\Models\Page;

/*
| The ported v2.1 admin talks to Woodex OS through one seam: `{action}` in, `{ok, ...}` out. These tests
| hold the two promises that seam makes — the OS session and the OS permission decide who may call it, and
| an action that has not been ported says so instead of pretending.
*/

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
    $this->actingAs($this->owner);
});

it('opens a ported screen inside the OS session', function () {
    $this->get(route('admin.website.forms'))
        ->assertOk()
        ->assertSee('Forms')
        ->assertSee('wx21-strip', false)
        ->assertSee('/assets/admin-v21/admin-forms.js', false);
});

it('lets only the four ported screens be reached by their hash', function () {
    // The copied v2.1 app still carries its own screens (Service pages, FAQs, Header & footer, Section library…)
    // and its router reads the URL hash. Those screens edit rows, and rows are not the website any more — so the
    // seam admits the four screens that are ported and nothing else. This is the list the adapter enforces.
    $source = (string) file_get_contents(public_path('assets/admin-v21/wx-embed.js'));

    expect($source)->toContain('var ALLOWED = ["blog", "portfolio", "forms", "estimator"];')
        ->and($source)->toContain('wx21-blocked')
        ->and($source)->toContain('its edits would not reach the website');

    // Each allowed screen renders; the old ones answer 404 from the server, so a bookmark cannot open them either.
    foreach (['blog', 'portfolio', 'forms'] as $screen) {
        $this->get(route('admin.website.'.$screen))->assertOk();
    }
    $this->get(route('admin.website.estimator'))->assertRedirect(route('admin.estimations.index', ['tab' => 'rates']));
});

it('does not open a screen that is not switched on', function () {
    $this->get('/admin/website/studio/media')->assertNotFound();
});

it('keeps people without website.edit out of the screen and the api', function () {
    $this->actingAs($this->userWithRole('sales'));

    $this->get(route('admin.website.forms'))->assertForbidden();
    $this->postJson(route('admin.website.studio.api'), ['action' => 'status'])->assertForbidden();
});

it('answers status and me from the OS session, never from a token header', function () {
    $this->postJson(route('admin.website.studio.api'), ['action' => 'status'])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('user.email', $this->owner->email);

    $this->postJson(route('admin.website.studio.api'), ['action' => 'me'], ['X-WX-ADM' => 'forged'])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('user.name', $this->owner->name);
});

it('says so when an action has not been ported, instead of pretending', function () {
    $this->postJson(route('admin.website.studio.api'), ['action' => 'media_list'])
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error', fn (string $error): bool => str_contains($error, 'not ported yet'));
});

it('refuses the actions that made a page row for a screen the OS does not keep', function () {
    // "New service page" and "Duplicate" wrote a `pages` row. Since the renderer was retired a row is not a
    // page, and the screen itself is behind the door (step 18) — so the action must refuse too, by name.
    $before = Page::query()->count();

    $this->postJson(route('admin.website.studio.api'), [
        'action' => 'cms_page_new',
        'title' => 'A service page',
        'slug' => '/a-service-page/',
    ])->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error', fn (string $error): bool => str_contains($error, 'Service pages')
            && str_contains($error, 'All pages and Page builder'));

    $this->postJson(route('admin.website.studio.api'), ['action' => 'cms_page_duplicate', 'id' => 1])
        ->assertOk()
        ->assertJsonPath('ok', false);

    expect(Page::query()->count())->toBe($before)
        ->and(Page::query()->where('slug', '/a-service-page/')->exists())->toBeFalse();
});

it('refuses the content-library actions too, and the screen is gone', function () {
    // Step 22: the FAQs / Testimonials / Client logos screens wrote `content_entries` rows, and the Content
    // library screen that managed them was out of the sidebar but still answering. The rows were read by
    // nothing in the file world, so the screen is retired and every action behind it refuses by name.
    foreach ([
        'cms_faqs' => 'FAQs',
        'cms_faq_save' => 'FAQs',
        'cms_testimonials' => 'Testimonials',
        'cms_testimonial_order' => 'Testimonials',
        'logos_get' => 'Client logos',
        'logos_save' => 'Client logos',
    ] as $action => $screen) {
        $this->postJson(route('admin.website.studio.api'), ['action' => $action])
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', fn (string $error): bool => str_contains($error, $screen));
    }

    $this->get('/admin/website/content')->assertNotFound();
});

it('refuses an upload that is not an image', function () {
    $this->postJson(route('admin.website.studio.api'), [
        'action' => 'media_upload',
        'name' => 'notes.txt',
        'data' => base64_encode('not an image at all'),
    ])->assertOk()->assertJsonPath('ok', false);
});
