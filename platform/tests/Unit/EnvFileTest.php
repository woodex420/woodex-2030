<?php

use App\Support\Install\EnvFile;
use Dotenv\Dotenv;

/*
| The installer writes .env; phpdotenv must read back exactly what the owner typed (database passwords!).
*/

it('stores every value so that phpdotenv reads it back unchanged', function (string $value) {
    $parsed = Dotenv::parse('DB_PASSWORD='.EnvFile::quote($value)."\n");

    expect($parsed['DB_PASSWORD'])->toBe($value);
})->with([
    'plain' => ['u123456789_woodex'],
    'url' => ['https://interior.woodex.com.pk'],
    'dollar' => ['pa$$word'],
    'interpolation' => ['a${HOME}b'],
    'double quote' => ['say "hi"'],
    'single quote' => ["it's"],
    'backslash' => ['back\\slash\\'],
    'hash' => ['#not-a-comment'],
    'spaces' => ['  two  spaces  '],
    'everything' => ["mix'\"\$\\ #=;"],
    'base64 key' => ['base64:'.base64_encode(random_bytes(32))],
    'empty' => [''],
]);

it('writes booleans and null the way Laravel reads them', function () {
    expect(EnvFile::quote(true))->toBe('true')
        ->and(EnvFile::quote(false))->toBe('false')
        ->and(EnvFile::quote(null))->toBe('')
        ->and(EnvFile::quote(3306))->toBe('3306');
});

it('refuses line breaks, which would inject extra settings', function () {
    EnvFile::quote("secret\nAPP_DEBUG=true");
})->throws(InvalidArgumentException::class);

it('renders sections, writes owner-only files and forgets single keys', function () {
    $path = sys_get_temp_dir().'/woodex-env-'.bin2hex(random_bytes(4));

    try {
        EnvFile::write($path, EnvFile::render([
            'Application' => ['APP_NAME' => 'Woodex OS', 'APP_DEBUG' => false],
            'Owner' => ['OWNER_EMAIL' => 'owner@woodex.test', 'OWNER_PASSWORD' => 'x$y'],
        ]));

        expect(fileperms($path) & 0777)->toBe(0600);
        $parsed = Dotenv::parse((string) file_get_contents($path));
        expect($parsed)->toMatchArray(['APP_NAME' => 'Woodex OS', 'APP_DEBUG' => 'false', 'OWNER_PASSWORD' => 'x$y']);

        EnvFile::forget($path, ['OWNER_PASSWORD']);
        $parsed = Dotenv::parse((string) file_get_contents($path));
        expect($parsed)->not->toHaveKey('OWNER_PASSWORD')
            ->and($parsed)->toHaveKey('OWNER_EMAIL')
            ->and((string) file_get_contents($path))->toContain('# Application');
    } finally {
        @unlink($path);
    }
});
