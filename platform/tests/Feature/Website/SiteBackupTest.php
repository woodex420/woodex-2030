<?php

declare(strict_types=1);

/*
| The site is `public/` now: the pages that ship with the repository, plus every page the owner publishes
| from the OS. Those newer files exist on that one server, and a deploy that replaces the folder would take
| them with it. `woodex:site-backup` is the copy that lives outside the deploy (storage/), and these tests hold
| what it must and must not put in it.
*/

beforeEach(function () {
    $this->site = storage_path('framework/testing/site-backup-'.uniqid());

    mkdir($this->site.'/about', 0o775, true);
    mkdir($this->site.'/insights/a-post', 0o775, true);
    mkdir($this->site.'/assets', 0o775, true);
    mkdir($this->site.'/media', 0o775, true);

    file_put_contents($this->site.'/index.html', '<html>home</html>');
    file_put_contents($this->site.'/about/index.html', '<html>about</html>');
    file_put_contents($this->site.'/insights/a-post/index.html', '<html>post</html>');
    // Not pages: the theme's assets, and a folder the media route owns.
    file_put_contents($this->site.'/assets/index.html', 'not a page');
    file_put_contents($this->site.'/media/index.html', 'not a page');

    $this->app->usePublicPath($this->site);

    $this->before = glob(storage_path('app/private/site-backups/site-*.zip')) ?: [];
});

afterEach(function () {
    foreach ((glob(storage_path('app/private/site-backups/site-*.zip')) ?: []) as $zip) {
        if (! in_array($zip, $this->before, true)) {
            @unlink($zip);
        }
    }

    foreach (['/insights/a-post', '/assets', '/media', '/about', '/insights', ''] as $folder) {
        foreach (glob($this->site.$folder.'/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->site.$folder);
    }
});

it('keeps a copy of every page file, and only pages', function () {
    expect($this->artisan('woodex:site-backup'))->toBe(0);

    $zips = array_values(array_diff(glob(storage_path('app/private/site-backups/site-*.zip')) ?: [], $this->before));

    expect($zips)->toHaveCount(1);

    $zip = new ZipArchive;
    expect($zip->open($zips[0]))->toBeTrue();

    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }

    $zip->close();

    expect($names)->toContain('index.html')
        ->and($names)->toContain('about/index.html')
        ->and($names)->toContain('insights/a-post/index.html')
        ->and($names)->not->toContain('assets/index.html')
        ->and($names)->not->toContain('media/index.html');
});

it('keeps only the copies it is told to keep', function () {
    // Two dated names that sort apart, so the older one is unambiguously the one to prune.
    $folder = storage_path('app/private/site-backups');

    if (! is_dir($folder)) {
        mkdir($folder, 0o775, true);
    }
    $old = $folder.'/site-20000101-000000.zip';
    file_put_contents($old, 'old');

    expect($this->artisan('woodex:site-backup --keep=1'))->toBe(0)
        ->and(is_file($old))->toBeFalse();

    @unlink($old);
});
