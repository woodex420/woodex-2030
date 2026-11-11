<?php

declare(strict_types=1);

use App\Domain\Website\Builder\BuilderFile;
use App\Domain\Website\Builder\SiteWideReplace;
use App\Livewire\Admin\Website\FindReplaceIndex;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| Find & replace across the page files (docs/audit/21 §5.1). The phone number is written into every page as
| ordinary text, so a change of number used to be 142 builder edits. The tests hold the four promises the
| screen makes: the preview writes nothing, a run keeps the before-image of every page it touches, undo puts
| them all back, and a stale preview cannot authorise a write.
*/

beforeEach(function () {
    $this->site = storage_path('framework/testing/find-'.uniqid());
    File::ensureDirectoryExists($this->site);
    File::ensureDirectoryExists($this->site.'/about');
    File::ensureDirectoryExists($this->site.'/insights/a-post');
    File::ensureDirectoryExists($this->site.'/contact');

    File::put($this->site.'/index.html', '<!doctype html><html><head><title>Home | Woodex</title></head><body>'
        .'<a href="tel:+923224000768">Call +92 322 4000768</a><p>Or +92 322 4000768 any day.</p></body></html>');
    File::put($this->site.'/about/index.html', '<!doctype html><html><head><title>About</title></head><body>'
        .'<p>Studio: +92 322 4000768</p></body></html>');
    File::put($this->site.'/insights/a-post/index.html', '<!doctype html><html><head><title>Post</title></head><body>'
        .'<p>Questions? +92 322 4000768</p></body></html>');
    File::put($this->site.'/contact/index.html', '<!doctype html><html><head><title>Contact</title></head><body>'
        .'<p>Use the form.</p></body></html>');

    $this->app->usePublicPath($this->site);

    $this->owner = $this->userWithRole('owner');
    $this->actingAs($this->owner);

    // The runs live in real storage; start each test with none of them.
    File::deleteDirectory(storage_path('app/private/find-replace'));
});

afterEach(function () {
    File::deleteDirectory($this->site);
    File::deleteDirectory(storage_path('app/private/find-replace'));
    File::deleteDirectory(storage_path('app/private/builder/'.sha1('index.html')));
    File::deleteDirectory(storage_path('app/private/builder/'.sha1('about/index.html')));
    File::deleteDirectory(storage_path('app/private/builder/'.sha1('insights/a-post/index.html')));
});

it('counts every match on every page without writing anything', function () {
    $before = File::get($this->site.'/index.html');

    $preview = (new SiteWideReplace($this->owner))->preview('+92 322 4000768');

    expect($preview['scanned'])->toBe(4)
        ->and($preview['total'])->toBe(4)
        ->and($preview['pages'])->toHaveCount(3)
        ->and($preview['pages'][0]['url'])->toBe('/')
        ->and($preview['pages'][0]['count'])->toBe(2)
        // The snippet is the sentence, not the surrounding markup or structured data.
        ->and($preview['pages'][0]['sample'])->toContain('Call +92 322 4000768')
        ->and($preview['pages'][0]['sample'])->not->toContain('<');

    // A preview is a read: the file on disk is untouched.
    expect(File::get($this->site.'/index.html'))->toBe($before);
    expect(is_dir(storage_path('app/private/find-replace')))->toBeFalse();
});

it('replaces in every page that has the text, and keeps the before-image of each', function () {
    $run = (new SiteWideReplace($this->owner))->apply('+92 322 4000768', '+92 300 1234567');

    expect($run['files'])->toBe(3)
        ->and($run['total'])->toBe(4);

    $home = File::get($this->site.'/index.html');
    expect($home)->toContain('+92 300 1234567')
        ->and($home)->not->toContain('+92 322 4000768')
        // The tel: link carries the digits without spaces and is left alone by this search — the screen says
        // every occurrence changes, and only the text matched.
        ->and($home)->toContain('tel:+923224000768');

    expect(File::get($this->site.'/about/index.html'))->toContain('+92 300 1234567');
    expect(File::get($this->site.'/contact/index.html'))->toContain('Use the form.');

    // The before-image of every touched page is on disk, and the builder also has a version of each.
    $kept = storage_path('app/private/find-replace/'.$run['run']);
    expect(File::get($kept.'/index.html'))->toContain('+92 322 4000768')
        ->and(File::get($kept.'/about/index.html'))->toContain('+92 322 4000768')
        ->and(is_file($kept.'/manifest.json'))->toBeTrue();

    $versions = collect(BuilderFile::versions('index.html'))->pluck('file')->all();
    expect(implode(' ', $versions))->toContain('find-replace');
});

it('puts every page of a run back in one press', function () {
    $originalHome = File::get($this->site.'/index.html');
    $originalAbout = File::get($this->site.'/about/index.html');

    $service = new SiteWideReplace($this->owner);
    $run = $service->apply('+92 322 4000768', '+92 300 1234567');

    $restored = $service->undo($run['run']);

    expect($restored['files'])->toBe(3)
        ->and($restored['missing'])->toBe(0)
        ->and(File::get($this->site.'/index.html'))->toBe($originalHome)
        ->and(File::get($this->site.'/about/index.html'))->toBe($originalAbout);

    expect($service->runs()[0]['undone'])->toBeTrue();

    // Undoing twice is refused by name rather than quietly reporting a restore that did nothing.
    expect(fn () => $service->undo($run['run']))->toThrow(ValidationException::class);
});

it('can be limited to one area of the site', function () {
    $run = (new SiteWideReplace($this->owner))->apply('+92 322 4000768', '+92 300 1234567', ['insights']);

    expect($run['files'])->toBe(1)
        ->and($run['total'])->toBe(1)
        ->and(File::get($this->site.'/insights/a-post/index.html'))->toContain('+92 300 1234567')
        ->and(File::get($this->site.'/index.html'))->toContain('+92 322 4000768');
});

it('writes nothing when no page contains the text', function () {
    expect(fn () => (new SiteWideReplace($this->owner))->apply('Not on the site', 'Anything'))
        ->toThrow(ValidationException::class);

    expect(is_dir(storage_path('app/private/find-replace')))->toBeFalse();
});

it('matches case only when asked', function () {
    File::put($this->site.'/about/index.html', '<html><body><p>WOODEX and Woodex</p></body></html>');

    $service = new SiteWideReplace($this->owner);
    $anyCase = $service->preview('woodex');
    $exact = $service->preview('woodex', [], true);

    // Two in the About body plus the home page's <title> — a site-wide search reads the whole document,
    // not only the visible text.
    expect($anyCase['total'])->toBe(3)
        ->and($exact['total'])->toBe(0);
});

it('previews, replaces and undoes from the screen, and refuses a stale preview', function () {
    Livewire::test(FindReplaceIndex::class)
        ->assertSee('Find & replace')
        // The areas are options, not the (empty) list of chosen ones — a Livewire property of the same name
        // once shadowed them and the checkboxes disappeared.
        ->assertSee('Lahore areas')
        ->assertSee('Insights')
        ->set('find', '+92 322 4000768')
        ->set('replace', '+92 300 1234567')
        ->call('runPreview')
        ->assertSet('preview.total', 4)
        ->assertSee('4 match(es)')
        // Changing the search after the preview makes the preview worthless.
        ->set('replace', '+92 300 0000000')
        ->call('apply')
        ->assertSet('result', fn (string $result): bool => str_contains($result, 'Preview again'));

    expect(File::get($this->site.'/index.html'))->toContain('+92 322 4000768');

    $run = Livewire::test(FindReplaceIndex::class)
        ->set('find', '+92 322 4000768')
        ->set('replace', '+92 300 1234567')
        ->call('runPreview')
        ->call('apply')
        ->assertSee('Replaced 4 match(es) in 3 page(s)');

    expect(File::get($this->site.'/index.html'))->toContain('+92 300 1234567');

    // The run is listed on the screen, and its Undo puts every page back.
    $run->call('undo', (new SiteWideReplace($this->owner))->runs()[0]['id'])
        ->assertSee('Restored 3 page(s)');

    expect(File::get($this->site.'/index.html'))->toContain('+92 322 4000768');
});

it('lets only a website editor open the screen', function () {
    $sales = User::factory()->create();
    $sales->givePermissionTo('leads.view');
    $this->actingAs($sales);

    $this->get(route('admin.website.find-replace'))->assertForbidden();
});
