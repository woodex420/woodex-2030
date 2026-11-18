<?php

use App\Support\Release;

/*
| Which release an installation runs, read from the file a packaged release carries in the website's folder
| (tools/hostinger/build-release.sh), or from Vercel's own variable. Shown on System → Health.
*/

beforeEach(function () {
    $this->site = sys_get_temp_dir().'/woodex-release-'.bin2hex(random_bytes(4));
    mkdir($this->site.'/platform', 0777, true);
});

afterEach(function () {
    if (is_file($this->site.'/'.Release::FILE)) {
        unlink($this->site.'/'.Release::FILE);
    }
    rmdir($this->site.'/platform');
    rmdir($this->site);
});

it('names the commit and the time of a packaged release, in the installation\'s own time zone', function () {
    file_put_contents($this->site.'/'.Release::FILE, json_encode([
        'commit' => '35c8a2cf67cbb40aa8af4c1283f9dc5d9660d5c8', 'uncommitted_changes' => false, 'built_at' => '2026-11-18T03:49:10Z',
    ]));

    expect(Release::describe($this->site.'/platform', null, 'Asia/Karachi'))->toBe('commit 35c8a2c, put together 18 Nov 2026, 08:49')
        ->and(Release::read($this->site.'/platform')['source'])->toBe('release');
});

it('says so when a release was put together from changes that were never committed', function () {
    file_put_contents($this->site.'/'.Release::FILE, json_encode(['commit' => 'c1f1f38', 'uncommitted_changes' => true, 'built_at' => '2026-11-18T03:49:10Z']));

    expect(Release::describe($this->site.'/platform'))->toBe('commit c1f1f38, put together 18 Nov 2026, 03:49 — with changes that were not committed');
});

it('names the commit Vercel built where there is no release file', function () {
    expect(Release::describe($this->site.'/platform', 'dfc83697afadd88b18820c53e02d5b1ea58943ed'))->toBe('commit dfc8369 (built by Vercel)');
});

it('names no release for a working copy, and takes nothing from a file it cannot read as a release', function (?string $content) {
    if ($content !== null) {
        file_put_contents($this->site.'/'.Release::FILE, $content);
    }

    expect(Release::describe($this->site.'/platform'))->toBe(Release::NONE)
        ->and(Release::read($this->site.'/platform'))->toBeNull();
})->with([
    'no file' => [null],
    'not JSON' => ['<html>'],
    'no commit' => ['{"built_at":"2026-11-18T03:49:10Z"}'],
    'a commit that is not one' => ['{"commit":"<script>alert(1)</script>"}'],
]);
