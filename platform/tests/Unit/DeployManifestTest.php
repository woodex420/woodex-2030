<?php

/*
| Hostinger's Git deploy serves the repository as-is (D-56): the root composer.json installs the same packages as
| platform/composer.json, the root .htaccess exposes only platform/public, and the front-end build is committed.
*/

function repositoryRoot(): string
{
    return dirname(__DIR__, 3);
}

it('installs exactly the platform packages from the root composer.json', function () {
    $root = json_decode((string) file_get_contents(repositoryRoot().'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $platform = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($root['require'])->toBe($platform['require'])
        ->and($root)->not->toHaveKey('require-dev')
        ->and($root['config']['vendor-dir'])->toBe('platform/vendor')
        ->and($root['config']['platform'])->toBe($platform['config']['platform'])
        ->and($root['autoload']['psr-4'])->toBe(array_map(fn (string $path): string => 'platform/'.$path, $platform['autoload']['psr-4']))
        ->and($root['scripts']['post-install-cmd'])->toBe(['@php tools/deploy/after-composer.php'])
        ->and($root['scripts']['post-update-cmd'])->toBe(['@php tools/deploy/after-composer.php'])
        ->and(is_file(repositoryRoot().'/tools/deploy/after-composer.php'))->toBeTrue();
})->skip(fn () => ! is_file(repositoryRoot().'/composer.json'), 'repository root not present (sandbox copy without sync)');

it('serves only platform/public from the repository root', function () {
    $htaccess = (string) file_get_contents(repositoryRoot().'/.htaccess');

    expect($htaccess)->toContain('Options -Indexes')
        ->toContain('RewriteRule ^(?!platform/public/)(.*)$ platform/public/$1 [L]')
        ->toContain('<FilesMatch "^WOODEX-SETUP-CODE">') // the installer's code copy in the site folder
        ->toContain('RewriteRule ^\.well-known/ - [L]')
        ->toContain("<IfModule !mod_rewrite.c>\n    Require all denied");
})->skip(fn () => ! is_file(repositoryRoot().'/.htaccess'), 'repository root not present (sandbox copy without sync)');

it('ships a complete front-end build because the server has no Node.js', function () {
    $platform = dirname(__DIR__, 2);
    $manifest = json_decode((string) file_get_contents($platform.'/public/build/manifest.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest)->toHaveKeys(['resources/css/admin.css', 'resources/js/admin.js', 'resources/css/site.css', 'resources/js/site.js']);

    foreach ($manifest as $entry) {
        expect(is_file($platform.'/public/build/'.$entry['file']))->toBeTrue("missing build file {$entry['file']}");
    }

    expect((string) file_get_contents($platform.'/.gitignore'))->not->toMatch('#^/public/build$#m');
});
