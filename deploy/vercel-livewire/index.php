<?php

declare(strict_types=1);

// This entry point is packaged separately; it never alters the React deployment.
$required = ['APP_KEY', 'DB_CONNECTION', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];
$ready = PHP_VERSION_ID >= 80401 && is_file(__DIR__.'/../vendor/autoload.php');
foreach ($required as $name) {
    $ready = $ready && is_string(getenv($name)) && getenv($name) !== '';
}
$ready = $ready && getenv('DB_CONNECTION') === 'mysql';
if (! $ready) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo 'Admin service is not configured.';
    exit;
}

$storage = '/tmp/woodex-livewire/storage';
foreach (['framework/views', 'framework/cache/data', 'framework/sessions', 'logs', 'app/private', 'app/public'] as $directory) {
    $path = $storage.'/'.$directory;
    if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
        http_response_code(503);
        exit('Admin service is unavailable.');
    }
}
putenv('LARAVEL_STORAGE_PATH='.$storage);
putenv('VIEW_COMPILED_PATH='.$storage.'/framework/views');
require __DIR__.'/../public/index.php';
