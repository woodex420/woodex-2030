<?php

declare(strict_types=1);

/*
 * Vercel entry point of the Laravel admin (docs/woodex-os/13-VERCEL-DEPLOYMENT.md). Never used by any other host.
 *
 * Vercel supplies the settings as server variables and its file system is read-only except /tmp. Two modes:
 *
 *   Demo  WOODEX_DEMO=1. Serves the sample-data workspace that the deployment built
 *         (tools/vercel/build-demo.php), copied to /tmp. Everything in it is fictional, and whatever is typed into
 *         it is lost when Vercel starts a new instance. This mode cannot reach a real database, and it encrypts
 *         with a key of its own, made by the build: never with the project's APP_KEY.
 *         WOODEX_DEMO_SHOW_PASSWORD=1 also prints the demo owner's password on the sign-in page (an open door:
 *         leave it off unless everyone may walk in).
 *   Real  APP_KEY and a MySQL database (DB_*). Creating the tables and the first owner account has no route on
 *         Vercel yet (the web installer writes files): see the document before configuring this.
 *
 * Anything else answers 503 without detail.
 */
// PHP announces itself and its version with every answer ("X-Powered-By: PHP/8.5.12"). Nobody needs to know.
header_remove('X-Powered-By');
$read = static fn (string $name): string => is_string($value = getenv($name)) ? $value : '';
// Laravel reads $_SERVER and $_ENV before getenv(), so a value that must win is written to all three.
$write = static function (string $name, string $value): void {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
};
$refuse = static function (string $message): never {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    echo $message;
    exit;
};

$demo = $read('WOODEX_DEMO') === '1';
$required = $demo ? [] : ['APP_KEY', 'DB_CONNECTION', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];
$ready = PHP_VERSION_ID >= 80401 && is_file(__DIR__.'/../vendor/autoload.php');
foreach ($required as $name) {
    $ready = $ready && $read($name) !== '';
}
$ready = $ready && ($demo || $read('DB_CONNECTION') === 'mysql');
if (! $ready) {
    $refuse('Admin service is not configured.');
}

$storage = '/tmp/woodex-livewire/storage';
foreach (['framework/views', 'framework/cache/data', 'framework/sessions', 'logs', 'app/private', 'app/public', 'fonts'] as $directory) {
    $path = $storage.'/'.$directory;
    if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
        $refuse('Admin service is unavailable.');
    }
}
// Laravel takes the storage path from $_ENV or $_SERVER only: putenv() alone left it in the read-only application folder.
$write('LARAVEL_STORAGE_PATH', $storage);
$write('VIEW_COMPILED_PATH', $storage.'/framework/views');
// The settings come from Vercel, not from a .env file: the site is configured and the installer must not open.
$write('WOODEX_CONFIG_FROM_SERVER', '1');

if ($demo) {
    $built = __DIR__.'/../database/woodex-synthetic-vercel-demo.sqlite';
    $database = '/tmp/woodex-synthetic-vercel-demo.sqlite';
    // The build made a key for this demo alone. With the project's APP_KEY, a link signed in the demo would also
    // be accepted by the real admin.
    $key = is_file($built.'.key') ? trim((string) file_get_contents($built.'.key')) : '';
    if (! is_file($built) || ! str_starts_with($key, 'base64:') || strlen($key) < 50) {
        $refuse('The demo workspace was not built with this deployment.');
    }
    if (! is_file($database) || filesize($database) === 0) {
        $partial = $database.'.'.getmypid();
        if (! @copy($built, $partial) || ! @rename($partial, $database)) {
            @unlink($partial);
            $refuse('Admin service is unavailable.');
        }
    }

    $open = $read('WOODEX_DEMO_SHOW_PASSWORD') === '1';
    $ownerPassword = $read('WX_PW_OWNER');

    // A demo must be unable to use anything real, also when the Vercel project carries real settings (a database,
    // a mailbox, WhatsApp, Telegram, Google) for another environment: every setting that names a server or holds a
    // key is emptied before Laravel starts. Request headers (HTTP_*) are not settings and stay as they are.
    $names = array_unique([...array_keys(getenv()), ...array_keys($_ENV), ...array_keys($_SERVER)]);
    foreach ($names as $name) {
        $name = (string) $name;
        if (str_starts_with($name, 'HTTP_')) {
            continue;
        }
        if (preg_match('/^(DB|LEGACY_DB|MAIL|REDIS|MEMCACHED|WHATSAPP|TELEGRAM|GOOGLE|TURNSTILE|AWS|POSTMARK|RESEND|SLACK|WX_PW)_|(_KEY|_TOKEN|_SECRET|_PASSWORD|_DSN|_WEBHOOK_URL)$|^OWNER_PASSWORD$/', $name) === 1) {
            $write($name, '');
        }
    }

    foreach ([
        // A demo is never "production": the sample-data notice shows only outside production and only on a SQLite
        // file named woodex-synthetic-* (App\Support\SyntheticPreview).
        'APP_ENV' => 'local',
        'APP_DEBUG' => 'false',
        'APP_KEY' => $key,
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $database,
        // Every instance has its own copy of the database, so the session travels in the (encrypted) cookie.
        'SESSION_DRIVER' => 'cookie',
        'CACHE_STORE' => 'database',
        'QUEUE_CONNECTION' => 'database',
        'MAIL_MAILER' => 'log',
        'BROADCAST_CONNECTION' => 'log',
        'ENFORCE_2FA' => 'false',
        'WOODEX_SYNTHETIC_PREVIEW' => 'true',
        'WOODEX_PREVIEW_LOGIN_EMAIL' => 'owner@woodex.test',
        'WOODEX_PREVIEW_SHOW_CREDENTIALS' => $open ? 'true' : 'false',
        'WOODEX_PREVIEW_LOGIN_PASSWORD' => $open ? $ownerPassword : '',
    ] as $name => $value) {
        $write($name, $value);
    }

    // The demo has no public website: its address opens the admin.
    if (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) === '/') {
        header('Location: /admin', true, 302);
        header('Cache-Control: no-store');
        exit;
    }
}

require __DIR__.'/../public/index.php';
