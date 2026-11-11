<?php

declare(strict_types=1);

use App\Domain\Website\Enums\PageStatus;
use App\Domain\Website\Enums\PageType;
use App\Domain\Website\Models\Media;
use App\Domain\Website\Models\Page;
use App\Domain\Website\Models\PageVersion;
use Illuminate\Testing\TestResponse;

/*
| The ported article/study editor speaks in one JSON payload; Woodex OS keeps a post as a page. These tests
| hold both directions: an imported article opens with its own words, and saving writes a page the site
| renders — without losing what was there before.
*/

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
    $this->actingAs($this->owner);

    $this->listing = Page::query()->create([
        'type' => PageType::Listing,
        'slug' => '/insights/',
        'title' => 'Insights',
        'status' => PageStatus::Published,
    ]);

    $this->media = Media::query()->create([
        'disk' => 'public',
        'path' => 'website/2026/10/hero.png',
        'alt' => 'Hero',
        'title' => 'Hero',
        'folder' => 'website/2026/10',
        'uploaded_by' => $this->owner->id,
    ]);

    // Publishing writes a page file now, so the site folder has to be a temporary tree: a test must never
    // leave a page in public/ that the next deploy would ship.
    $this->site = storage_path('framework/testing/studio-site-'.uniqid());
    mkdir($this->site.'/insights/template', 0o775, true);
    mkdir($this->site.'/projects/template', 0o775, true);

    $shell = '<!doctype html><html lang="en"><head><title>Template</title>'
        .'<meta name="description" content="Template" />'
        .'<link rel="canonical" href="https://woodex.com.pk/insights/template/" />'
        .'</head><body><header id="site-header">Woodex</header><main id="main-content"></main>'
        .'<footer id="site-footer">Footer</footer></body></html>';

    file_put_contents($this->site.'/insights/template/index.html', $shell);
    file_put_contents($this->site.'/projects/template/index.html', str_replace('insights/', 'projects/', $shell));

    $this->app->usePublicPath($this->site);
});

afterEach(function () {
    studioContentRemoveTree($this->site);

    foreach (glob(storage_path('app/studio-retired/insights/*/*.html')) ?: [] as $kept) {
        @unlink($kept);
        @rmdir(dirname($kept));
    }
});

/** Remove a temporary site tree. */
function studioContentRemoveTree(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    foreach (glob($path.'/*') ?: [] as $entry) {
        is_dir($entry) ? studioContentRemoveTree($entry) : @unlink($entry);
    }

    @rmdir($path);
}

/** @param array<string, mixed> $payload */
function studioApi(array $payload): TestResponse
{
    return test()->postJson(route('admin.website.studio.api'), $payload);
}

it('lists the insight pages as articles, and nothing else', function () {
    Page::query()->create(['type' => PageType::Insight, 'slug' => '/insights/one/', 'title' => 'First article', 'status' => PageStatus::Published]);
    Page::query()->create(['type' => PageType::Service, 'slug' => '/interior-design/', 'title' => 'A service', 'status' => PageStatus::Published]);

    $response = studioApi(['action' => 'cms_list', 'type' => 'post'])->assertOk()->assertJsonPath('ok', true);

    expect($response->json('items'))->toHaveCount(1)
        ->and($response->json('items.0.title'))->toBe('First article')
        ->and($response->json('items.0.slug'))->toBe('one')
        ->and($response->json('items.0.status'))->toBe('published');
});

it('opens an imported article with its own words', function () {
    $page = Page::query()->create([
        'type' => PageType::Insight,
        'slug' => '/insights/imported/',
        'title' => 'Imported article',
        'excerpt' => 'A standfirst from v1.',
        'status' => PageStatus::Published,
        'featured_image_id' => $this->media->id,
    ]);

    $page->blocks()->create([
        'type' => 'hero',
        'block_key' => 'bhero001',
        'data' => ['eyebrow' => 'Joinery', 'heading' => 'Imported article', 'text' => 'A standfirst from v1.', 'image_id' => $this->media->id],
        'visible' => true,
        'position' => 0,
    ]);

    $page->blocks()->create([
        'type' => 'text',
        'block_key' => 'btext001',
        'data' => ['heading' => 'The short comparison', 'body' => "First paragraph.\n\nSecond paragraph."],
        'visible' => true,
        'position' => 1,
    ]);

    $item = studioApi(['action' => 'cms_get', 'id' => $page->id, 'type' => 'post'])->assertOk()->json('item');

    expect($item['title'])->toBe('Imported article')
        ->and($item['data']['kicker'])->toBe('Joinery')
        ->and($item['data']['dek'])->toBe('A standfirst from v1.')
        ->and($item['data']['hero']['src'])->toContain('/media/'.$this->media->id.'/')
        ->and($item['data']['blocks'][0])->toBe(['t' => 'h', 'text' => 'The short comparison'])
        ->and($item['data']['blocks'][1]['text'])->toBe('First paragraph.')
        ->and($item['data']['blocks'][2]['text'])->toBe('Second paragraph.');
});

it('shows the article’s own artwork in the list, through the library row that holds the alt', function () {
    // An imported article keeps its hero as a block (image_id), not as featured_image_id; the list used to draw
    // an empty grey square for all 48 of them. The card now falls back to that hero, and the URL is the media
    // route, which serves the row's file and its alt.
    $page = Page::query()->create([
        'type' => PageType::Insight,
        'slug' => '/insights/with-artwork/',
        'title' => 'With artwork',
        'status' => PageStatus::Published,
    ]);

    $page->blocks()->create([
        'type' => 'hero',
        'block_key' => 'bhero777',
        'data' => ['eyebrow' => 'Joinery', 'heading' => 'With artwork', 'image_id' => $this->media->id],
        'visible' => true,
        'position' => 0,
    ]);

    $item = studioApi(['action' => 'cms_list', 'type' => 'post'])->assertOk()->json('items.0');

    expect($item['data']['card']['src'])->toContain('/media/'.$this->media->id.'/');

    // The alt the Media screen holds travels with the hero into the editor, not an empty string.
    $opened = studioApi(['action' => 'cms_get', 'id' => $page->id, 'type' => 'post'])->assertOk()->json('item.data');

    expect($opened['hero']['alt'])->toBe('Hero')
        ->and($opened['card']['src'])->toContain('/media/'.$this->media->id.'/');
});

it('creates a draft article as an insight page under /insights/', function () {
    studioApi([
        'action' => 'cms_save',
        'type' => 'post',
        'title' => 'Kitchen costs in Lahore',
        'slug' => 'kitchen-costs',
        'data' => [
            'kicker' => 'Costs',
            'dek' => 'What drives the price.',
            'hero' => ['src' => '/media/'.$this->media->id.'/hero.png?v=1'],
            'blocks' => [['t' => 'h', 'text' => 'The numbers'], ['t' => 'p', 'text' => 'Joinery is most of it.']],
            'meta' => [['k' => 'Read time', 'v' => '6 min']],
        ],
        'seo' => ['title' => 'Kitchen costs in Lahore', 'description' => 'What drives the price.', 'kw' => 'kitchen cost lahore'],
    ])->assertOk()->assertJsonPath('ok', true);

    $page = Page::query()->where('slug', '/insights/kitchen-costs/')->firstOrFail();

    expect($page->type)->toBe(PageType::Insight)
        ->and($page->status)->toBe(PageStatus::Draft)
        ->and($page->parent_id)->toBe($this->listing->id)
        ->and($page->excerpt)->toBe('What drives the price.')
        ->and($page->featured_image_id)->toBe($this->media->id)
        ->and($page->seo['focus_keyword'])->toBe('kitchen cost lahore')
        ->and($page->blocks()->where('type', 'text')->firstOrFail()->data['studio']['blocks'])->toHaveCount(2);
});

it('keeps the previous blocks as a version before every save', function () {
    $page = Page::query()->create([
        'type' => PageType::Insight,
        'slug' => '/insights/versioned/',
        'title' => 'Versioned',
        'status' => PageStatus::Draft,
    ]);

    $page->blocks()->create(['type' => 'text', 'block_key' => 'bold0001', 'data' => ['heading' => 'Old heading', 'body' => 'Old body'], 'visible' => true, 'position' => 0]);

    studioApi([
        'action' => 'cms_save',
        'type' => 'post',
        'id' => $page->id,
        'title' => 'Versioned',
        'slug' => 'versioned',
        'data' => ['blocks' => [['t' => 'p', 'text' => 'New body']]],
        'seo' => [],
    ])->assertOk()->assertJsonPath('ok', true);

    $version = PageVersion::query()->where('page_id', $page->id)->firstOrFail();

    expect($version->data['blocks'][0]['data']['body'])->toBe('Old body');
});

it('refuses an address another page already uses', function () {
    Page::query()->create(['type' => PageType::Insight, 'slug' => '/insights/taken/', 'title' => 'Taken', 'status' => PageStatus::Draft]);

    studioApi([
        'action' => 'cms_save',
        'type' => 'post',
        'title' => 'Another',
        'slug' => 'taken',
        'data' => [],
        'seo' => [],
    ])->assertOk()->assertJsonPath('ok', false);
});

it('publishes an article and puts its card on the index', function () {
    $page = Page::query()->create([
        'type' => PageType::Insight,
        'slug' => '/insights/to-publish/',
        'title' => 'To publish',
        'status' => PageStatus::Draft,
    ]);

    $this->listing->blocks()->create([
        'type' => 'services',
        'block_key' => 'blist0009',
        'data' => ['heading' => 'Featured', 'items' => []],
        'visible' => true,
        'position' => 0,
    ]);

    studioApi(['action' => 'cms_published', 'id' => $page->id, 'type' => 'post'])
        ->assertOk()
        ->assertJsonPath('item.status', 'published');

    $items = $this->listing->blocks()->where('type', 'services')->firstOrFail()->refresh()->data['items'];

    expect($page->refresh()->status)->toBe(PageStatus::Published)
        ->and($items)->toHaveCount(1)
        ->and($items[0]['url'])->toBe('/insights/to-publish/');
});

it('leaves the page a draft when its file cannot be written', function () {
    // A file where the folder should be: mkdir() cannot make the folder, so the page cannot exist.
    file_put_contents($this->site.'/insights/blocked-address', 'not a folder');

    $page = Page::query()->create([
        'type' => PageType::Insight,
        'slug' => '/insights/blocked-address/',
        'title' => 'Blocked address',
        'status' => PageStatus::Draft,
    ]);

    studioApi(['action' => 'cms_published', 'id' => $page->id, 'type' => 'post'])
        ->assertOk()
        ->assertJsonPath('ok', false);

    expect($page->refresh()->status)->toBe(PageStatus::Draft)
        ->and($page->published_at)->toBeNull()
        ->and(is_file($this->site.'/insights/blocked-address/index.html'))->toBeFalse();
});

it('publishes the same page twice without failing on the folder it already made', function () {
    $page = Page::query()->create([
        'type' => PageType::Insight,
        'slug' => '/insights/twice/',
        'title' => 'Twice',
        'status' => PageStatus::Draft,
    ]);

    studioApi(['action' => 'cms_published', 'id' => $page->id, 'type' => 'post'])->assertOk()->assertJsonPath('ok', true);
    studioApi(['action' => 'cms_published', 'id' => $page->id, 'type' => 'post'])->assertOk()->assertJsonPath('ok', true);

    expect(is_file($this->site.'/insights/twice/index.html'))->toBeTrue();
});

it('tells the editor which file a published page lives in', function () {
    $page = Page::query()->create([
        'type' => PageType::Insight,
        'slug' => '/insights/has-a-file/',
        'title' => 'Has a file',
        'status' => PageStatus::Draft,
    ]);

    // A draft has no file, so the editor keeps offering Publish now (and a draft action).
    expect(studioApi(['action' => 'cms_get', 'id' => $page->id, 'type' => 'post'])->json('item.rel'))->toBeNull();

    studioApi(['action' => 'cms_published', 'id' => $page->id, 'type' => 'post'])->assertOk()->assertJsonPath('ok', true);

    // After publishing the editor must see the file: that is what turns Publish now into Publish changes.
    expect(studioApi(['action' => 'cms_get', 'id' => $page->id, 'type' => 'post'])->json('item.rel'))
        ->toBe('insights/has-a-file/index.html');
});

it('refuses to publish without website.publish', function () {
    $editor = $this->userWithRole('editor');
    $this->actingAs($editor);

    $page = Page::query()->create([
        'type' => PageType::Insight,
        'slug' => '/insights/editor-draft/',
        'title' => 'Editor draft',
        'status' => PageStatus::Draft,
    ]);

    studioApi(['action' => 'cms_published', 'id' => $page->id, 'type' => 'post'])->assertForbidden();
});

it('deletes a draft but never a live page', function () {
    $draft = Page::query()->create(['type' => PageType::Insight, 'slug' => '/insights/trash-me/', 'title' => 'Trash me', 'status' => PageStatus::Draft]);
    $live = Page::query()->create(['type' => PageType::Insight, 'slug' => '/insights/live-one/', 'title' => 'Live one', 'status' => PageStatus::Published]);

    studioApi(['action' => 'cms_delete', 'id' => $draft->id, 'type' => 'post'])->assertOk()->assertJsonPath('ok', true);
    studioApi(['action' => 'cms_delete', 'id' => $live->id, 'type' => 'post'])->assertOk()->assertJsonPath('ok', false);

    expect(Page::query()->whereKey($draft->id)->exists())->toBeFalse()
        ->and(Page::query()->whereKey($live->id)->exists())->toBeTrue();
});

it('saves a study as a project page under /projects/', function () {
    Page::query()->create(['type' => PageType::Listing, 'slug' => '/projects/', 'title' => 'Projects', 'status' => PageStatus::Published]);

    studioApi([
        'action' => 'cms_save',
        'type' => 'study',
        'title' => 'A clinic that calms',
        'slug' => 'clinic',
        'data' => ['kicker' => 'Design study', 'blocks' => [['t' => 'p', 'text' => 'Quiet rooms.']]],
        'seo' => [],
    ])->assertOk()->assertJsonPath('ok', true);

    $page = Page::query()->where('slug', '/projects/clinic/')->firstOrFail();

    expect($page->type)->toBe(PageType::Project)
        ->and($page->blocks()->where('type', 'text')->firstOrFail()->data['studio']['kicker'])->toBe('Design study');
});
