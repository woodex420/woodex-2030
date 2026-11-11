<?php

use App\Domain\Website\Models\Page;
use App\Domain\Website\Models\Redirect;
use App\Livewire\Admin\Website\SeoOverviewIndex;
use App\Livewire\Admin\Website\SiteHealthIndex;
use App\Models\User;
use App\Support\AdminNavigation;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithPublicWebsite;

/*
| The old admin v2.1 pro's Website section, item for item (docs/14 §5a, 19 Oct): the four screens it
| had that Woodex OS lacked — Section library, Hero slides, SEO and Site health — plus the old names
| on the screens that already existed under different labels (Header & footer, Redirects, All pages,
| Page builder). One flat sidebar group carries everything the owner starred.
*/

uses(InteractsWithPublicWebsite::class);

beforeEach(function () {
    $this->editor = User::factory()->create();
    $this->editor->givePermissionTo(['website.edit', 'website.publish']);
});

it('groups the Website section the way it is worked on', function () {
    $owner = User::factory()->create();
    $owner->givePermissionTo([
        'dashboard.view', 'website.edit', 'website.publish', 'theme.manage', 'settings.manage',
        'leads.view', 'contacts.view', 'estimates.view', 'quotes.view', 'quotes.approve', 'invoices.view',
        'accounts.view', 'projects.view', 'inbox.view', 'reports.view', 'automation.manage',
        'integrations.manage', 'social.manage', 'users.manage', 'system.health', 'audit.view',
        'system.change_requests', 'leads.delete', 'system.import',
    ]);

    $groups = collect(AdminNavigation::for($owner));

    $labels = fn (?string $title): array => collect($groups->firstWhere('title', $title)['items'])
        ->pluck('label')
        ->all();

    // Build a page, fill it with content, then tune the whole site — the owner's three bands.
    // Section library and Page templates left the sidebar with the block editor (step 6): they fed a
    // screen that wrote rows, and the files never read them.
    expect($labels('Pages & builder'))->toBe([
        'All pages', 'Page builder', 'Find & replace', 'Redirects',
    ])->and($labels('Content'))->toBe([
        'Blog', 'Portfolio', 'Forms',
    ])->and($labels('Website'))->toBe([
        'Media', 'SEO', 'SEO agent', 'Speed', 'Site health', 'Theme', 'Website Settings',
    ]);

    // Every Content item has a route, so nothing is listed disabled. The four database-only screens
    // (Service pages, City pages, FAQs, Testimonials) were retired with the database-page model (§16.19).
    $planned = collect($groups->firstWhere('title', 'Content')['items'])
        ->whereNull('route')
        ->pluck('label')
        ->all();

    expect($planned)->toBe([]);
});

it('retires the Content library completely', function () {
    $owner = User::factory()->create();
    $owner->givePermissionTo(['website.edit']);

    $all = collect(AdminNavigation::for($owner))->flatMap(fn (array $group): array => $group['items'])->pluck('label');

    expect($all)->not->toContain('Content library');

    // Step 22: the screen was out of the sidebar on 20 Oct but its route still answered and its forms still
    // saved rows — none of which any page file read. It is gone now, not merely hidden: no route, no route name.
    expect(Route::has('admin.website.content'))->toBeFalse();

    $this->actingAs($owner)->get('/admin/website/content')->assertNotFound();
});

it('shows every page\'s search listing on the SEO screen, read from the files', function () {
    $site = sys_get_temp_dir().'/woodex-seo-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($site.'/about');
    File::ensureDirectoryExists($site.'/quiet');
    File::put($site.'/index.html', '<html><head><title>Woodex Interior — design &amp; build in Lahore</title>'
        .'<meta name="description" content="Interior design, fit-out and architecture in Lahore since 2016, by one studio team."></head>'
        .'<body><main><h1>Woodex</h1><p>'.str_repeat('word ', 200).'</p></main></body></html>');
    File::put($site.'/about/index.html', '<html><head><title>About the studio</title></head>'
        .'<body><main><h1>About</h1><p>'.str_repeat('word ', 150).'</p></main></body></html>');
    File::put($site.'/quiet/index.html', '<html><head><title>Quiet page</title></head>'
        .'<body><main><h1>Quiet</h1><p>'.str_repeat('word ', 150).'</p></main></body></html>');
    $this->app->usePublicPath($site);

    Livewire::actingAs($this->editor)
        ->test(SeoOverviewIndex::class)
        // The default view is the worklist: the pages with a finding, read from their files.
        ->assertSee('/about/')
        ->assertSee('About the studio')
        ->assertSee('No description')
        ->assertDontSee('/quiet/thin-', false)
        ->call('setFilter', 'all')
        ->assertSee('/about/')
        ->assertSee('/quiet/')
        // The listing is changed in the builder, on the file — not in a row nothing reads.
        ->assertSee('Open in builder');

    File::deleteDirectory($site);
});

it('reports the whole site\'s health from the files, and catches a redirect to nowhere', function () {
    $site = sys_get_temp_dir().'/woodex-health-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($site.'/about');
    File::put($site.'/index.html', '<html><head><title>Woodex home page in Lahore</title>'
        .'<meta name="description" content="Interior design in Lahore, from one studio team that draws, builds and hands over."></head>'
        .'<body><main><h1>Woodex</h1><a href="/about/">About</a></main></body></html>');
    File::put($site.'/about/index.html', '<html><head><title>About the Woodex studio</title>'
        .'<meta name="description" content="The studio, its people and how a project runs from the first visit to handover."></head>'
        .'<body><main><h1>About</h1><a href="/nowhere/">A dead link</a></main></body></html>');
    $this->app->usePublicPath($site);

    Redirect::query()->create(['from_path' => '/old-page/', 'to_path' => '/does-not-exist/', 'code' => 301, 'note' => null]);

    Livewire::actingAs($this->editor)
        ->test(SiteHealthIndex::class)
        ->assertSee('Internal links that lead nowhere')
        ->assertSee('/nowhere/')
        ->assertSee('Redirects pointing at no page')
        ->assertSee('/old-page/')
        ->assertSee('robots.txt is generated')
        ->assertSee('sitemap.xml is generated');

    File::deleteDirectory($site);
});

it('keeps the standalone screens behind the website permission', function () {
    $intruder = User::factory()->create(); // no website.edit

    foreach ([
        '/admin/website/seo-overview',
        '/admin/website/health',
    ] as $path) {
        $this->actingAs($intruder)->get($path)->assertForbidden();
        $this->actingAs($this->editor)->get($path)->assertOk();
    }
});

it('retires the screens that only wrote rows the files never read', function (string $url) {
    $owner = User::factory()->create();
    $owner->givePermissionTo(['website.edit']);

    $all = collect(AdminNavigation::for($owner))->flatMap(fn (array $group): array => $group['items'])->pluck('label');

    expect($all)
        ->not->toContain('Section library')
        ->not->toContain('Page templates')
        ->not->toContain('Header & footer')
        ->not->toContain('Team');

    $this->actingAs($owner)->get($url)->assertNotFound();
})->with([
    '/admin/website/sections',
    '/admin/website/templates',
    // Their records stay (the Blade layout and the importer still read them); the screens only edited
    // rows no file page renders.
    '/admin/website/menus',
    '/admin/website/team',
]);

it('retires the four database-only screens instead of leaving a destination that cannot reach the site', function (string $url) {
    $owner = User::factory()->create();
    $owner->givePermissionTo(['website.edit']);

    $all = collect(AdminNavigation::for($owner))->flatMap(fn (array $group): array => $group['items'])->pluck('label');

    expect($all)
        ->not->toContain('Service pages')
        ->not->toContain('City pages')
        ->not->toContain('FAQs')
        ->not->toContain('Testimonials');

    // The URLs are gone with the screens — not hidden rows that still write a page nobody serves.
    $this->actingAs($owner)->get($url)->assertNotFound();
})->with([
    '/admin/website/services',
    '/admin/website/cities',
    '/admin/website/faqs',
    '/admin/website/testimonials',
]);

it('keeps the screens that still have work to do', function (string $url) {
    $owner = User::factory()->create();
    $owner->givePermissionTo(['website.edit']);

    if ($url === '/admin/website/estimator') {
        $this->actingAs($owner)->get($url)->assertRedirect(route('admin.estimations.index', ['tab' => 'rates']));
    } else {
        $this->actingAs($owner)->get($url)->assertOk();
    }
})->with([
    '/admin/website/forms',
    '/admin/website/estimator',
]);

it('retires the home screens now that / is a file', function (string $url) {
    $owner = User::factory()->create();
    $owner->givePermissionTo(['website.edit']);

    $all = collect(AdminNavigation::for($owner))->flatMap(fn (array $group): array => $group['items'])->pluck('label');

    // The home page is public/index.html (§16.19 step 4): a screen that writes the CMS home row can no
    // longer put a word on the live page, so it is not a destination.
    expect($all)
        ->not->toContain('Hero slides')
        ->not->toContain('Client logos');

    $this->actingAs($owner)->get($url)->assertNotFound();
})->with([
    '/admin/website/heroes',
    '/admin/website/logos',
]);
