<?php

use App\Support\Install\SiteState;

/*
| Where a Git-deployed site keeps .env and storage (D-57): outside the folder Hostinger replaces on every deploy.
*/

function siteStateSandbox(): string
{
    $root = sys_get_temp_dir().'/woodex-site-state-'.bin2hex(random_bytes(4));
    mkdir($root.'/home/u123/domains/interior.woodex.com.pk/public_html/platform', 0777, true);

    return $root;
}

function removeTree(string $path): void
{
    if (! file_exists($path)) {
        return;
    }

    if (is_dir($path) && ! is_link($path)) {
        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            removeTree($path.'/'.$entry);
        }
        rmdir($path);
    } else {
        unlink($path);
    }
}

it('names the data folder after the Hostinger site folder', function (string $base, string $key) {
    expect((new SiteState($base, '/home/u123'))->siteKey())->toBe($key);
})->with([
    'own website' => ['/home/u123/domains/interior.woodex.com.pk/public_html/platform', 'interior.woodex.com.pk'],
    'subdomain folder' => ['/home/u123/domains/woodex.com.pk/public_html/interior/platform', 'woodex.com.pk-interior'],
    'nested folder' => ['/home/u123/domains/woodex.com.pk/public_html/apps/os/platform', 'woodex.com.pk-apps-os'],
    'older layout' => ['/home/u123/public_html/interior/platform', 'public_html-interior'],
    'outside the home folder' => ['/srv/woodex/platform', 'srv-woodex'],
]);

it('puts the data folder in the home folder, outside every public_html', function () {
    $site = new SiteState('/home/u123/domains/interior.woodex.com.pk/public_html/platform', '/home/u123');

    expect($site->externalDirectory())->toBe('/home/u123/woodex-os/interior.woodex.com.pk')
        ->and($site->displayPath('/home/u123/woodex-os/interior.woodex.com.pk/.env'))->toBe('woodex-os/interior.woodex.com.pk/.env')
        ->and($site->insideWebFolder())->toBeTrue()
        ->and((new SiteState('/home/u123/apps/woodexinterior/platform', '/home/u123'))->insideWebFolder())->toBeFalse()
        ->and((new SiteState('/home/u123/domains/x/public_html/platform', null))->externalDirectory())->toBeNull();
});

it('finds the home folder from WOODEX_HOME or the /home path, never inside a web folder', function () {
    $_SERVER['WOODEX_HOME'] = '/home/u123/domains/interior.woodex.com.pk/public_html';

    try {
        // An override inside public_html is refused; the /home/<user> path is used instead.
        expect(SiteState::detect('/home/u123/domains/interior.woodex.com.pk/public_html/platform')->home)->toBe('/home/u123');

        $_SERVER['WOODEX_HOME'] = '/data/accounts/u9';
        expect(SiteState::detect('/data/accounts/u9/sites/os/platform')->home)->toBe('/data/accounts/u9');
    } finally {
        unset($_SERVER['WOODEX_HOME']);
    }

    expect(SiteState::detect('/srv/woodex/platform')->home)->toBeIn([null, getenv('HOME') ?: null]);
});

it('uses platform/.env first, then the data folder, and is unconfigured without either', function () {
    $root = siteStateSandbox();
    $home = $root.'/home/u123';
    $base = $home.'/domains/interior.woodex.com.pk/public_html/platform';

    try {
        $site = new SiteState($base, $home);
        expect($site->isConfigured())->toBeFalse()
            ->and($site->environmentDirectory())->toBeNull()
            ->and($site->installDirectory())->toBe($home.'/woodex-os/interior.woodex.com.pk')
            ->and(is_dir($home.'/woodex-os/interior.woodex.com.pk'))->toBeTrue();

        file_put_contents($home.'/woodex-os/interior.woodex.com.pk/.env', "APP_KEY=x\n");
        expect($site->isConfigured())->toBeTrue()
            ->and($site->usesExternalState())->toBeTrue()
            ->and($site->environmentDirectory())->toBe($home.'/woodex-os/interior.woodex.com.pk');

        file_put_contents($base.'/.env', "APP_KEY=y\n");
        expect($site->usesClassicLayout())->toBeTrue()
            ->and($site->usesExternalState())->toBeFalse()
            ->and($site->environmentDirectory())->toBe($base);
    } finally {
        removeTree($root);
    }
});

it('falls back to platform/ when there is no safe home folder', function () {
    $root = siteStateSandbox();
    $base = $root.'/home/u123/domains/interior.woodex.com.pk/public_html/platform';

    try {
        expect((new SiteState($base, null))->installDirectory())->toBe($base);
    } finally {
        removeTree($root);
    }
});

it('creates the storage folders Laravel needs', function () {
    $root = siteStateSandbox();

    try {
        SiteState::ensureStorage($root.'/storage');

        foreach (SiteState::STORAGE_TREE as $folder) {
            expect(is_dir($root.'/storage/'.$folder))->toBeTrue();
        }
    } finally {
        removeTree($root);
    }
});
