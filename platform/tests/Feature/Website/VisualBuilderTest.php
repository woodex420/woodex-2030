<?php

declare(strict_types=1);

use App\Domain\Website\Builder\BuilderFile;
use App\Domain\Website\Models\Page;
use App\Domain\Website\Services\SiteRouter;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

/*
| The Visual Page Builder, Woodex OS 1: the page it opens is the HTML file the web server serves
| (docs/audit/16.19). These tests swap the application's public and storage paths for a scratch site, so a
| save writes a real file and the snapshot it keeps is a real file too — nothing here touches the shipped
| v1 pages.
*/

beforeEach(function () {
    $this->site = sys_get_temp_dir().'/woodex-builder-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->site.'/public/about');
    File::ensureDirectoryExists($this->site.'/public/service/roofing');
    File::ensureDirectoryExists($this->site.'/storage');

    $this->about = '<!DOCTYPE html><html lang="en"><head><title>About us</title></head><body><main><h1>Hello</h1></main></body></html>';
    $this->service = '<!DOCTYPE html><html lang="en"><head><title>Roofing</title><script src="/assets/site.js"></script></head><body><h1>Roofing</h1></body></html>';

    File::put($this->site.'/public/about/index.html', $this->about."\n");
    File::put($this->site.'/public/service/roofing/index.html', $this->service."\n");

    $this->app->usePublicPath($this->site.'/public');
    $this->app->useStoragePath($this->site.'/storage');

    $this->editor = User::factory()->create();
    $this->editor->givePermissionTo(['website.edit', 'website.publish']);

    $this->owner = User::factory()->create();
    $this->owner->givePermissionTo(['website.edit', 'website.publish', 'website.raw_html']);
});

afterEach(function () {
    File::deleteDirectory($this->site);
});

function builderApi(User $user, array $payload): TestResponse
{
    return test()->actingAs($user)->postJson(route('admin.website.builder.api'), $payload);
}

it('requires the website.edit permission', function () {
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(route('admin.website.builder'))
        ->assertForbidden();

    builderApi($stranger, ['action' => 'pages'])->assertForbidden();
});

it('opens the builder on the first page of the site, or the one asked for', function () {
    $this->actingAs($this->editor)
        ->get(route('admin.website.builder'))
        ->assertOk()
        ->assertSee('WX_BUILDER_BOOT', false)
        ->assertSee('data-boot-page="about/index.html"', false)
        ->assertDontSee('login-pass', false)
        ->assertSee('/builder/builder.js', false);

    $this->actingAs($this->editor)
        ->get(route('admin.website.builder', ['path' => 'service/roofing/index.html']))
        ->assertOk()
        ->assertSee('data-boot-page="service/roofing/index.html"', false);
});

it('lists the home file first, and calls it /', function () {
    File::put($this->site.'/public/index.html', '<!DOCTYPE html><html><head><title>Home</title></head><body><h1>Home</h1></body></html>');

    $pages = builderApi($this->editor, ['action' => 'pages'])->assertOk()->json('pages');

    expect($pages[0]['path'])->toBe('index.html')
        ->and($pages[0]['url'])->toBe('/')
        ->and($pages[0]['title'])->toBe('Home')
        ->and(array_column($pages, 'path'))->toContain('about/index.html');
});

it('lists the site files, not database pages', function () {
    Page::factory()->create(['slug' => '/about/', 'title' => 'A database copy']);

    $pages = builderApi($this->editor, ['action' => 'pages'])->assertOk()->json('pages');

    expect(array_column($pages, 'path'))->toBe(['about/index.html', 'service/roofing/index.html'])
        ->and($pages[0]['url'])->toBe('/about/')
        ->and($pages[1]['url'])->toBe('/service/roofing/')
        ->and($pages[0]['title'])->toBe('About us');
});

it('loads the HTML file itself, with its mtime and hash', function () {
    $response = builderApi($this->editor, ['action' => 'load', 'path' => 'about/index.html'])
        ->assertOk()
        ->assertJsonPath('html', $this->about."\n");

    expect($response->json('mtime'))->toBe(BuilderFile::mtime('about/index.html'))
        ->and($response->json('hash'))->toBe(sha1($this->about."\n"));
});

it('refuses a path outside the site', function (string $path) {
    builderApi($this->editor, ['action' => 'load', 'path' => $path])->assertStatus(422);
})->with([
    '../.env',
    'admin/index.html',
    'assets/index.html',
    'builder/index.html',
]);

it('saves straight to the page file and keeps the previous version', function () {
    $hash = sha1($this->about."\n");

    builderApi($this->editor, [
        'action' => 'save',
        'path' => 'about/index.html',
        'html' => str_replace('Hello', 'Hello again', $this->about),
        'hash' => $hash,
    ])->assertOk();

    expect(File::get($this->site.'/public/about/index.html'))->toContain('Hello again');

    $versions = BuilderFile::versions('about/index.html');

    expect($versions)->toHaveCount(1)
        ->and(BuilderFile::version('about/index.html', $versions[0]['file']))->toBe($this->about."\n");
});

it('refuses a stale save and writes nothing', function () {
    // Somebody edited the file after the builder opened it.
    File::put($this->site.'/public/about/index.html', str_replace('Hello', 'Somebody else', $this->about));

    builderApi($this->editor, [
        'action' => 'save',
        'path' => 'about/index.html',
        'html' => str_replace('Hello', 'Mine', $this->about),
        'hash' => sha1($this->about."\n"),
    ])->assertStatus(409);

    expect(File::get($this->site.'/public/about/index.html'))->toContain('Somebody else');
});

it('forces a save when the editor confirms the overwrite', function () {
    File::put($this->site.'/public/about/index.html', str_replace('Hello', 'Somebody else', $this->about));

    builderApi($this->editor, [
        'action' => 'save',
        'path' => 'about/index.html',
        'html' => str_replace('Hello', 'Mine', $this->about),
        'hash' => sha1($this->about."\n"),
        'force' => true,
    ])->assertOk();

    expect(File::get($this->site.'/public/about/index.html'))->toContain('Mine');
});

it('rejects editor markup and incomplete documents', function () {
    builderApi($this->editor, [
        'action' => 'save',
        'path' => 'about/index.html',
        'html' => '<html><body><h1 contenteditable="true">oops</h1></body></html>',
        'force' => true,
    ])->assertStatus(422);

    builderApi($this->editor, [
        'action' => 'save',
        'path' => 'about/index.html',
        'html' => '<h1>just a fragment</h1>',
        'force' => true,
    ])->assertStatus(422);

    expect(File::get($this->site.'/public/about/index.html'))->toBe($this->about."\n");
});

it('lets an editor keep existing code but not add new code', function () {
    // Same script, different text: allowed.
    builderApi($this->editor, [
        'action' => 'save',
        'path' => 'service/roofing/index.html',
        'html' => str_replace('Roofing', 'Roofing and more', $this->service),
        'force' => true,
    ])->assertOk();

    // A script the site has never used: refused for an editor, allowed for the owner.
    builderApi($this->editor, [
        'action' => 'save',
        'path' => 'about/index.html',
        'html' => '<html><head><script>alert(1)</script></head><body>x</body></html>',
        'force' => true,
    ])->assertStatus(422);

    builderApi($this->owner, [
        'action' => 'save',
        'path' => 'about/index.html',
        'html' => '<html><head><script>alert(1)</script></head><body>x</body></html>',
        'force' => true,
    ])->assertOk();

    expect(File::get($this->site.'/public/about/index.html'))->toContain('alert(1)');
});

it('lists backups and restores one', function () {
    builderApi($this->editor, [
        'action' => 'save',
        'path' => 'about/index.html',
        'html' => str_replace('Hello', 'Second', $this->about),
        'force' => true,
    ])->assertOk();

    $backups = builderApi($this->editor, ['action' => 'backups', 'path' => 'about/index.html'])->json('backups');

    expect($backups)->toHaveCount(1);

    builderApi($this->editor, [
        'action' => 'restore',
        'path' => 'about/index.html',
        'file' => $backups[0]['file'],
    ])->assertOk();

    expect(File::get($this->site.'/public/about/index.html'))->toBe($this->about."\n");
});

it('creates a new page file from the builder', function () {
    builderApi($this->editor, [
        'action' => 'page_new',
        'folder' => '',
        'slug' => 'new-page',
        'html' => '<!DOCTYPE html><html><head><title>New page</title></head><body><p>New</p></body></html>',
    ])->assertOk()->assertJsonPath('path', 'new-page/index.html');

    expect(File::exists($this->site.'/public/new-page/index.html'))->toBeTrue();

    // The same address twice is refused rather than overwritten.
    builderApi($this->editor, [
        'action' => 'page_new',
        'folder' => '',
        'slug' => 'new-page',
        'html' => '<!DOCTYPE html><html><head><title>New page</title></head><body><p>Again</p></body></html>',
    ])->assertStatus(422);
});

it('serves the saved file, because the file is the site', function () {
    $request = Request::create('/about/', 'GET');

    // Nothing in the database answers /about/ — before OS 1 this page only existed as a row.
    expect(Page::query()->where('slug', '/about/')->exists())->toBeFalse()
        ->and(app(SiteRouter::class)->respond($request))->toBeNull();
});

it('sends the old page-editor URL to the builder, on the file that answers the page', function () {
    // The block editor wrote rows the file site never read (OS 1, step 6). Its URL still has to land
    // somewhere useful: the builder, on the file that answers the page.
    $page = Page::factory()->create(['slug' => '/built/']);

    $this->actingAs($this->editor)
        ->get(route('admin.website.pages.edit', $page))
        ->assertRedirect(route('admin.website.builder', ['path' => 'built/index.html']));

    $this->actingAs($this->editor)
        ->get(route('admin.website.pages.create'))
        ->assertRedirect(route('admin.website.builder'));
});
