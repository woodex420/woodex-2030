<?php

declare(strict_types=1);

use App\Domain\Website\Enums\BlockType;
use App\Domain\Website\Enums\PageStatus;
use App\Domain\Website\Enums\PageType;
use App\Domain\Website\Models\Page;
use App\Domain\Website\Models\PageBlock;
use App\Domain\Website\Models\PageVersion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/*
| Woodex OS 1 (docs/audit/16.19): the database copies of pages that exist as v1 files are deleted with
| their blocks and versions — and only those. Two rules matter more than the delete itself: a published
| page with no file is kept (it is the only copy, and the renderer that used to export it is gone), and a
| draft is kept too (it was never live).
*/

beforeEach(function () {
    $this->backup = sys_get_temp_dir().'/woodex-retire-'.bin2hex(random_bytes(4));

    // A scratch site: the command deletes rows the files answer, and the suite must never touch a shipped
    // page.
    $this->site = sys_get_temp_dir().'/woodex-site-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->site);
    $this->app->usePublicPath($this->site);

    $this->shadow = collect([
        PageType::Service, PageType::City, PageType::Area, PageType::Project,
    ])->map(function (PageType $type) {
        // Published, like the rows the command was written for: a draft without a file is kept (see below).
        $page = Page::factory()->published()->create([
            'type' => $type,
            'slug' => '/'.$type->value.'-one/',
        ]);

        PageBlock::factory()->for($page)->asType(BlockType::Text, ['heading' => 'A section'])->create(['position' => 0]);
        PageVersion::query()->create([
            'page_id' => $page->id,
            'data' => ['canvas_html' => '<html><body>old</body></html>'],
        ]);

        return $page;
    });

    $this->kept = Page::factory()->create([
        'type' => PageType::Insight,
        'slug' => '/insight-kept/',
        'status' => PageStatus::Published,
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->backup);
    File::deleteDirectory($this->site);
});

it('only reports what it would delete until --force is given', function () {
    expect($this->artisan('website:retire-shadow-pages', ['--backup-dir' => $this->backup]))->toBe(0)
        ->and(Artisan::output())->toContain('Nothing was deleted');

    expect(Page::query()->count())->toBe(5)
        ->and(PageBlock::query()->count())->toBe(4)
        ->and(PageVersion::query()->count())->toBe(4)
        ->and(is_dir($this->backup) ? File::files($this->backup) : [])->toBe([]);
});

it('deletes the shadow pages, keeps the rest, and backs up everything it deleted', function () {
    // Every shadow page has its file, so nothing needs exporting.
    foreach (['service-one', 'city-one', 'area-one', 'project-one'] as $folder) {
        File::ensureDirectoryExists($this->site.'/'.$folder);
        File::put($this->site.'/'.$folder.'/index.html', '<html><body>file</body></html>');
    }

    expect($this->artisan('website:retire-shadow-pages', ['--force' => true, '--backup-dir' => $this->backup]))->toBe(0)
        ->and(Artisan::output())->toContain('Deleted 4 pages');

    expect(Page::query()->pluck('id')->all())->toBe([$this->kept->id])
        ->and(PageBlock::query()->count())->toBe(0)
        ->and(PageVersion::query()->count())->toBe(0);

    $files = File::files($this->backup);

    expect($files)->toHaveCount(1);

    $backup = json_decode((string) file_get_contents($files[0]->getPathname()), true);

    expect($backup['types'])->toBe(['service', 'city', 'area', 'project'])
        ->and($backup['pages'])->toHaveCount(4)
        ->and(array_column($backup['pages'], 'slug'))->toContain('/service-one/', '/city-one/', '/area-one/', '/project-one/')
        ->and($backup['pages'][0]['blocks'])->toHaveCount(1)
        ->and($backup['pages'][0]['versions'])->toHaveCount(1);
});

it('keeps a published page that has no file, because no renderer can write one', function () {
    // No files at all. Deleting these rows would take the only copy of four published pages, and the CMS
    // renderer that used to export them is gone — so the command keeps them and says so.
    expect($this->artisan('website:retire-shadow-pages', ['--force' => true, '--backup-dir' => $this->backup]))->toBe(0)
        ->and(Artisan::output())->toContain('Kept (no file of its own');

    foreach ($this->shadow as $page) {
        expect(Page::query()->whereKey($page->id)->exists())->toBeTrue();
        expect(File::exists($this->site.'/'.trim($page->slug, '/').'/index.html'))->toBeFalse();
    }

    expect(Page::query()->count())->toBe(5);
});

it('keeps a draft that has no file instead of publishing it by exporting it', function () {
    $draft = Page::factory()->create([
        'type' => PageType::Service,
        'slug' => '/service-draft/',
        'status' => PageStatus::Draft,
    ]);

    expect($this->artisan('website:retire-shadow-pages', ['--force' => true, '--backup-dir' => $this->backup]))->toBe(0)
        ->and(Artisan::output())->toContain('Kept (draft');

    expect(Page::query()->whereKey($draft->id)->exists())->toBeTrue()
        ->and(File::exists($this->site.'/service-draft/index.html'))->toBeFalse();
});

it('leaves the home row alone unless --home is given, then deletes it too', function () {
    $home = Page::factory()->create(['type' => PageType::Home, 'slug' => '/', 'title' => 'Home']);
    $home->blocks()->create([
        'type' => BlockType::Slider,
        'block_key' => 'bhome001',
        'position' => 0,
        'visible' => true,
        'data' => ['slides' => [['heading' => 'The home opening']]],
    ]);

    // The home page is a file now, so the row is a plain copy — and the four shadow pages need theirs, or
    // the command keeps their rows (a published page with no file has no other copy).
    File::put($this->site.'/index.html', '<html><body>home</body></html>');

    foreach (['service-one', 'city-one', 'area-one', 'project-one'] as $folder) {
        File::ensureDirectoryExists($this->site.'/'.$folder);
        File::put($this->site.'/'.$folder.'/index.html', '<html><body>file</body></html>');
    }

    expect($this->artisan('website:retire-shadow-pages', ['--force' => true, '--backup-dir' => $this->backup]))->toBe(0);

    expect(Artisan::output())->toContain('Deleted 4 pages');

    expect(Page::query()->whereKey($home->id)->exists())->toBeTrue();

    $files = File::files($this->backup);
    $first = json_decode((string) file_get_contents($files[0]->getPathname()), true);
    expect($first['types'])->toBe(['service', 'city', 'area', 'project']);

    expect($this->artisan('website:retire-shadow-pages', [
        '--force' => true, '--home' => true, '--backup-dir' => $this->backup,
    ]))->toBe(0);

    expect(Artisan::output())->toContain('Deleted 1 pages');

    expect(Page::query()->whereKey($home->id)->exists())->toBeFalse()
        ->and(Page::query()->count())->toBe(1);

    $files = File::files($this->backup);
    $second = json_decode((string) file_get_contents($files[count($files) - 1]->getPathname()), true);

    expect($second['types'])->toBe(['service', 'city', 'area', 'project', 'home'])
        ->and($second['pages'])->toHaveCount(1)
        ->and($second['pages'][0]['slug'])->toBe('/')
        ->and($second['pages'][0]['blocks'])->toHaveCount(1);
});

it('really deletes, so a restored backup can use the same slug again', function () {
    // A file for each, or the command keeps the row (a published page with no file has no other copy).
    foreach (['service-one', 'city-one', 'area-one', 'project-one'] as $folder) {
        File::ensureDirectoryExists($this->site.'/'.$folder);
        File::put($this->site.'/'.$folder.'/index.html', '<html><body>file</body></html>');
    }

    $this->artisan('website:retire-shadow-pages', ['--force' => true, '--backup-dir' => $this->backup]);

    // A soft delete would keep the unique slug taken and the page's content on disk.
    expect(Page::query()->withTrashed()->where('slug', '/service-one/')->exists())->toBeFalse()
        ->and(PageBlock::query()->count())->toBe(0);

    $restored = Page::factory()->create(['type' => PageType::Service, 'slug' => '/service-one/']);

    expect($restored->exists)->toBeTrue();
});

it('purges rows an earlier run only soft-deleted', function () {
    // A soft-deleted row still holds the unique slug and the page's blocks; a second run must finish the job.
    Page::query()->whereKeyNot($this->kept->id)->delete();

    expect(Page::withTrashed()->count())->toBe(5);

    expect($this->artisan('website:retire-shadow-pages', ['--force' => true, '--backup-dir' => $this->backup]))->toBe(0);

    // One call: the buffered console output is consumed when it is read.
    $out = Artisan::output();

    expect($out)->toContain('Already retired (soft-deleted): 4')
        ->and($out)->toContain('Deleted 4 pages');

    expect(Page::withTrashed()->count())->toBe(1);
});

it('says so instead of failing when there is nothing to retire', function () {
    Page::query()->whereKeyNot($this->kept->id)->forceDelete();

    expect($this->artisan('website:retire-shadow-pages', ['--force' => true, '--backup-dir' => $this->backup]))->toBe(0)
        ->and(Artisan::output())->toContain('Nothing to do');

    expect(Page::query()->count())->toBe(1);
});
