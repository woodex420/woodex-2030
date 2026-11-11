<?php

use App\Domain\Website\Builder\BuilderFile;
use App\Domain\Website\Models\Media;
use App\Domain\Website\Models\Page;
use App\Domain\Website\Models\Redirect;
use App\Livewire\Admin\Website\MediaLibrary;
use App\Livewire\Admin\Website\PagesIndex;
use App\Livewire\Admin\Website\RedirectsIndex;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithPublicWebsite;

/*
| The website screens (docs/09 §2.2): a page is created and published in the dashboard, the completeness gate
| holds a thin page back, a publish is kept as a version, and the 301 map is imported from a CSV.
*/
uses(InteractsWithPublicWebsite::class);

beforeEach(function () {
    // Whoever edits the website may also publish it, in these tests unless stated otherwise.
    $this->editor = User::factory()->create();
    $this->editor->givePermissionTo(['website.edit', 'website.publish']);
});

it('lists the pages of the site as the files they are', function () {
    // A page is a file under public/, so the list is the builder's own page list, not the pages table.
    $site = sys_get_temp_dir().'/woodex-pages-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($site.'/about');
    File::ensureDirectoryExists($site.'/insights');
    File::put($site.'/index.html', '<html><head><title>Woodex home</title></head><body></body></html>');
    File::put($site.'/about/index.html', '<html><head><title>About the studio</title></head><body></body></html>');
    File::put($site.'/insights/index.html', '<html><head><title>Insights</title></head><body></body></html>');
    File::ensureDirectoryExists($site.'/insights/a-post');
    File::put($site.'/insights/a-post/index.html', '<html><head><title>An article</title></head><body></body></html>');
    $this->app->usePublicPath($site);

    $this->actingAs($this->editor);

    Livewire::test(PagesIndex::class)
        ->assertSee('Woodex home')
        ->assertSee('About the studio')
        ->assertSee('/about/')
        ->assertSee('Edit in builder')
        // The filter groups the pages that sit under one: /insights/a-post/ belongs to insights, while
        // /insights/ itself and /about/ are top-level pages.
        ->set('section', 'insights')
        ->assertSee('An article')
        ->assertDontSee('About the studio');

    // Nothing on the screen is a database row: an empty table still lists the site.
    expect(Page::query()->count())->toBe(0);

    File::deleteDirectory($site);
});

it('takes a page offline by moving its file out of the site, and keeps it', function () {
    $site = sys_get_temp_dir().'/woodex-pages-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($site.'/about');
    File::put($site.'/about/index.html', '<html><head><title>About the studio</title></head><body></body></html>');
    $this->app->usePublicPath($site);

    $this->actingAs($this->editor);

    Livewire::test(PagesIndex::class)
        ->call('retire', 'about/index.html')
        ->assertDispatched('toast');

    // The URL is gone, the HTML is not: a version and the moved file both survive in storage.
    expect(is_file($site.'/about/index.html'))->toBeFalse();
    expect(BuilderFile::versions('about/index.html'))->not->toBe([]);

    $kept = glob(storage_path('app/private/site-retired/*/about/index.html')) ?: [];
    expect($kept)->not->toBe([]);

    foreach ($kept as $file) {
        @unlink($file);
    }

    File::deleteDirectory($site);
});

it('lets an editor take a page offline only with the publish permission', function () {
    $writer = User::factory()->create();
    $writer->givePermissionTo('website.edit');
    $this->actingAs($writer);

    Livewire::test(PagesIndex::class)
        ->assertDontSee('Take offline')
        ->call('retire', 'about/index.html')
        ->assertStatus(403);
});

it('keeps the website screens away from users who do not edit the website', function () {
    $sales = $this->userWithRole('sales');

    $this->actingAs($sales)
        ->get(route('admin.website.pages'))
        ->assertForbidden();
});

it('uploads an image, requires alt text and serves it from the media library', function () {
    Storage::fake('public');
    $this->actingAs($this->editor);

    $file = UploadedFile::fake()->image('living-room.jpg', 1200, 800);

    Livewire::test(MediaLibrary::class)
        ->set('uploads', [$file])
        ->call('save');

    $media = Media::query()->sole();

    expect($media->width)->toBe(1200)
        ->and($media->height)->toBe(800)
        ->and(Storage::disk('public')->exists($media->path))->toBeTrue();

    // The image is reachable at its URL, and the file is read from storage, not from the web folder.
    $served = $this->get($media->url())->assertOk();

    expect($served->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=31536000')->toContain('immutable');

    // Alt text is edited in place.
    Livewire::test(MediaLibrary::class)
        ->set('alt.'.$media->id, 'Living room with timber panelling and linen seating')
        ->call('updateMeta', $media->id);

    expect($media->fresh()->alt)->toBe('Living room with timber panelling and linen seating');
});

it('writes edited alt text into the pages that show the image', function () {
    // The pages are files (Woodex OS 1): saving the row alone changed nothing the site could show.
    $site = sys_get_temp_dir().'/woodex-alt-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($site.'/about');
    File::put($site.'/about/index.html', '<html><body><main>'
        .'<img src="/assets/img/img-abc.webp" srcset="/assets/img/img-abc-480.webp 480w, /assets/img/img-abc-960.webp 960w" alt="Old wording">'
        .'<img src="/assets/img/img-other.webp" alt="">'
        .'</main></body></html>');
    File::put($site.'/index.html', '<html><body><img src="/assets/img/img-abc.webp"></body></html>');
    $this->app->usePublicPath($site);

    $media = Media::factory()->create(['path' => 'website/imported/img-abc.webp', 'alt' => 'Old wording']);
    $variant = Media::factory()->create(['path' => 'website/imported/img-abc-960.webp', 'alt' => 'Another wording']);
    Media::factory()->create(['path' => 'website/imported/img-other.webp', 'alt' => 'Other photo']);

    $this->actingAs($this->editor);

    Livewire::test(MediaLibrary::class)
        ->set('alt.'.$media->id, 'Living room with a timber ceiling')
        ->call('updateMeta', $media->id);

    $about = (string) file_get_contents($site.'/about/index.html');
    $home = (string) file_get_contents($site.'/index.html');

    expect($about)->toContain('alt="Living room with a timber ceiling"')
        ->and($about)->not->toContain('Old wording')
        ->and($about)->toContain('<img src="/assets/img/img-other.webp" alt="">')
        ->and($home)->toContain('alt="Living room with a timber ceiling"')
        ->and($media->fresh()->alt)->toBe('Living room with a timber ceiling')
        ->and($variant->fresh()->alt)->toBe('Living room with a timber ceiling');

    File::deleteDirectory($site);
});

it('refuses to delete an image a page still shows, and deletes one no page uses', function () {
    $site = sys_get_temp_dir().'/woodex-alt-delete-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($site.'/about');
    File::put($site.'/about/index.html', '<html><body><img src="/assets/img/img-used.webp" alt="Used"></body></html>');
    File::put($site.'/index.html', '<html><body></body></html>');
    $this->app->usePublicPath($site);

    Storage::fake('public');

    $used = Media::factory()->create(['path' => 'website/imported/img-used.webp']);
    Storage::disk('public')->put($used->path, 'image');
    $free = Media::factory()->create(['path' => 'website/imported/img-free.webp']);
    Storage::disk('public')->put($free->path, 'image');

    $this->actingAs($this->editor);

    Livewire::test(MediaLibrary::class)->call('delete', $used->id)->assertDispatched('toast', type: 'error');
    expect(Media::query()->count())->toBe(2);

    Livewire::test(MediaLibrary::class)->call('delete', $free->id);
    expect(Media::query()->count())->toBe(1)
        ->and(Storage::disk('public')->exists($free->path))->toBeFalse();

    File::deleteDirectory($site);
});

it('imports the redirect map from a CSV and answers each old URL with one 301', function () {
    $this->actingAs($this->editor);
    $before = Redirect::query()->count();

    Livewire::test(RedirectsIndex::class)
        ->set('csv', "/old-flat-design/,/interior-design/,301,was a service page\n/old-house-design/,/architecture/,301\nnot a url,/interior-design/")
        ->call('importCsv')
        ->assertSee('2 redirect(s) imported');

    expect(Redirect::query()->count())->toBe($before + 2);

    Page::factory()->published()->withSlug('/interior-design/')->create(['title' => 'Interior Design']);
    Page::factory()->published()->withSlug('/architecture/')->create(['title' => 'Architecture']);

    $this->siteGet('/old-flat-design/')->assertRedirect($this->siteUrl('/interior-design/'))->assertStatus(301);
    $this->siteGet('/old-house-design/')->assertRedirect($this->siteUrl('/architecture/'))->assertStatus(301);

    // A second import updates the rows instead of duplicating them.
    Livewire::test(RedirectsIndex::class)->set('csv', '/old-flat-design/,/architecture/,301')->call('importCsv');
    expect(Redirect::query()->count())->toBe($before + 2)
        ->and(Redirect::query()->where('from_path', '/old-flat-design/')->first()->to_path)->toBe('/architecture/');
});

it('refuses a redirect from an address that is still a page, because the file answers first', function () {
    // The website is the files and the web server answers a file before the app runs: a redirect from a URL
    // that is still a page would never fire, so it is refused with the reason instead of stored as a dead row.
    $site = sys_get_temp_dir().'/woodex-redirect-file-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($site.'/about');
    File::put($site.'/index.html', '<html><head><title>Home</title></head><body></body></html>');
    File::put($site.'/about/index.html', '<html><head><title>About</title></head><body></body></html>');
    $this->app->usePublicPath($site);

    $this->actingAs($this->editor);

    Livewire::test(RedirectsIndex::class)
        ->set('fromPath', '/about/')
        ->set('toPath', '/contact/')
        ->call('add')
        ->assertDispatched('toast', type: 'error')
        ->assertSee('still a page');

    expect(Redirect::query()->where('from_path', '/about/')->exists())->toBeFalse();

    // An address with no file is the one that can be redirected, and the screen says when the target is missing.
    Livewire::test(RedirectsIndex::class)
        ->set('fromPath', '/old-about/')
        ->set('toPath', '/about/')
        ->call('add')
        ->assertDispatched('toast', type: 'success')
        ->assertSee('It is live.');

    expect(Redirect::query()->where('from_path', '/old-about/')->exists())->toBeTrue();

    File::deleteDirectory($site);
});

it('skips imported rows whose old URL is still a page, and reads a spreadsheet paste', function () {
    $site = sys_get_temp_dir().'/woodex-redirect-import-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($site.'/about');
    File::put($site.'/index.html', '<html><head><title>Home</title></head><body></body></html>');
    File::put($site.'/about/index.html', '<html><head><title>About</title></head><body></body></html>');
    $this->app->usePublicPath($site);

    $this->actingAs($this->editor);
    $before = Redirect::query()->count();

    Livewire::test(RedirectsIndex::class)
        ->set('csv', "/about/,/contact/,301,still a page\n/old-tab/\t/about/\t301\tfrom a spreadsheet")
        ->call('importCsv')
        ->assertSee('1 redirect(s) imported')
        ->assertSee('1 skipped because the old URL is still a page');

    expect(Redirect::query()->count())->toBe($before + 1)
        ->and(Redirect::query()->where('from_path', '/about/')->exists())->toBeFalse()
        ->and(Redirect::query()->where('from_path', '/old-tab/')->first()?->note)->toBe('from a spreadsheet');

    File::deleteDirectory($site);
});

it('marks redirects that cannot fire, so the screen never counts a dead row as a working 301', function () {
    $site = sys_get_temp_dir().'/woodex-redirect-inert-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($site.'/about');
    File::put($site.'/index.html', '<html><head><title>Home</title></head><body></body></html>');
    File::put($site.'/about/index.html', '<html><head><title>About</title></head><body></body></html>');
    $this->app->usePublicPath($site);

    // A row that got in some other way (a SQL import, a page file added later) is shown as not firing.
    Redirect::query()->create(['from_path' => '/about/', 'to_path' => '/contact/', 'code' => 301]);
    Redirect::query()->create(['from_path' => '/old-page/', 'to_path' => '/about/', 'code' => 301]);

    $this->actingAs($this->editor);

    Livewire::test(RedirectsIndex::class)
        ->assertSee('1 redirect(s) cannot fire')
        ->assertSee('not firing')
        ->assertSee('/old-page/');

    File::deleteDirectory($site);
});

it('exports the redirect map as a CSV', function () {
    Redirect::factory()->create(['from_path' => '/old-page/', 'to_path' => '/interior-design/']);
    $this->actingAs($this->editor);

    Livewire::test(RedirectsIndex::class)->call('exportCsv')
        ->assertFileDownloaded();

    $this->get(route('admin.website.redirects'))->assertOk()->assertSee('/old-page/');
});
