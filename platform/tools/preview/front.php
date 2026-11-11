<?php

declare(strict_types=1);
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Woodex\Sandbox\NativePreviewTransport;

/**
 * Front controller for a SYNTHETIC local preview, for PHP's built-in web server:
 *
 *     php -S 0.0.0.0:8080 -t public tools/preview/front.php
 *
 * It is a development tool. No production entry point loads it, and it refuses to serve anything but a local
 * application on a disposable SQLite database whose file name starts with "woodex-synthetic-".
 *
 *  1. Files under public/ are served as files; dot-files are refused, as public/.htaccess does.
 *  2. Everything else runs through Laravel's HTTP kernel exactly as public/index.php does — the same middleware,
 *     so sign-in, CSRF, permission and module checks are untouched.
 *  3. Absolute self-URLs in a response become root-relative, so navigation stays on whichever host the browser
 *     uses (a preview proxy). Signed Livewire snapshots are not altered.
 *  4. Preview proxies that remove the Cookie and Set-Cookie headers: see "cookie jar" below.
 *  5. Request header NAMES and cookie NAMES (never values) are logged to storage/logs/preview-requests.log.
 */
$base = dirname(__DIR__, 2);
$public = $base.'/public';
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$started = microtime(true);

$names = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with($key, 'HTTP_')) {
        $names[] = strtolower(str_replace('_', '-', substr($key, 5)));
    }
}
sort($names);
$log = static function (int|string $status) use ($base, $path, $names, $started): void {
    @file_put_contents($base.'/storage/logs/preview-requests.log', sprintf(
        "%s %s %s -> %s %dms host=%s headers=[%s] cookies=[%s]\n",
        date('c'), $_SERVER['REQUEST_METHOD'] ?? '-', $path, $status, (int) round((microtime(true) - $started) * 1000),
        preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '?', (string) ($_SERVER['HTTP_HOST'] ?? '-')),
        implode(',', $names), implode(',', array_keys($_COOKIE)),
    ), FILE_APPEND | LOCK_EX);
};

// Dot-files are never served (public/.htaccess: RewriteRule (^|/)\.(?!well-known/) - [F,L]).
if (preg_match('#(^|/)\.(?!well-known/)#', $path) === 1) {
    http_response_code(403);
    $log(403);
    exit('Forbidden');
}

if ($path !== '/' && ! str_ends_with($path, '.php') && is_file($public.$path)) {
    if (str_ends_with($path, '.mjs')) { // the built-in server has no type for .mjs; public/.htaccess sets it
        header('Content-Type: text/javascript');
        header('Content-Length: '.filesize($public.$path));
        readfile($public.$path);
        $log('200 static');
        exit;
    }
    $log('static');

    return false;
}

// There is no public website in this preview: the root opens the admin.
if ($path === '/') {
    header('Location: /admin', true, 302);
    $log(302);
    exit;
}

// A preview proxy terminates TLS. Anything that is not a loopback request is HTTPS as far as the browser knows.
$host = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
if (! in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

$_SERVER['SCRIPT_FILENAME'] = $public.'/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

define('LARAVEL_START', microtime(true));

require $base.'/vendor/autoload.php';
require __DIR__.'/NativePreviewTransport.php';

/** @var Application $app */
$app = require_once $base.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

// Synthetic preview only. This is what makes the relaxations below acceptable.
$database = (string) config('database.connections.sqlite.database');
$synthetic = $app->environment('local')
    && config('database.default') === 'sqlite'
    && str_starts_with(basename($database), 'woodex-synthetic-')
    && in_array(config('mail.default'), ['log', 'array'], true);
if (! $synthetic) {
    http_response_code(503);
    $log(503);
    exit('This preview front controller serves only a local application on a synthetic SQLite database.');
}

/*
 * Cookie jar. Measured on the hosting platform's preview proxy: it removes the Cookie and Set-Cookie headers, so
 * a correct password was accepted and the very next request arrived without a session. For requests that come
 * through that proxy — they carry its access header and no Cookie header — the browser's cookies are kept here,
 * on the server, instead. Sign-in, password, CSRF and permission checks are unchanged.
 *
 * Consequence, stated plainly: everyone who can open the preview address shares ONE session, so a sign-in by one
 * viewer signs in the others until sign-out or two idle hours. The header's value is never read, stored or logged.
 */
$viaProxy = isset($_SERVER['HTTP_E2B_TRAFFIC_ACCESS_TOKEN']) && ! isset($_SERVER['HTTP_COOKIE']);
$jarFile = $base.'/storage/framework/preview-viewer.jar.json';
$jar = ['seen' => 0, 'cookies' => []];
if ($viaProxy) {
    $stored = is_file($jarFile) ? json_decode((string) file_get_contents($jarFile), true) : null;
    if (is_array($stored) && ($stored['seen'] ?? 0) > time() - 7200 && is_array($stored['cookies'] ?? null)) {
        $jar = $stored;
    }
    $pairs = [];
    foreach ($jar['cookies'] as $name => $value) {
        $_COOKIE[$name] = $value;
        $pairs[] = $name.'='.rawurlencode((string) $value);
    }
    if ($pairs !== []) {
        $_SERVER['HTTP_COOKIE'] = implode('; ', $pairs);
    }
}

$request = Request::capture();
$response = $kernel->handle($request);

if ($viaProxy) {
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->isCleared() || (string) $cookie->getValue() === '') {
            unset($jar['cookies'][$cookie->getName()]);
        } else {
            $jar['cookies'][$cookie->getName()] = $cookie->getValue();
        }
    }
    $jar['seen'] = time();
    file_put_contents($jarFile, json_encode($jar), LOCK_EX);
    @chmod($jarFile, 0600);
}

$origins = array_unique([$request->getSchemeAndHttpHost(), rtrim((string) config('app.url'), '/')]);

// A redirect to the bare origin (sign-out goes to "/") would become an empty target once made relative.
if ($response instanceof RedirectResponse && in_array(rtrim($response->getTargetUrl(), '/'), $origins, true)) {
    $response->setTargetUrl('/');
}

foreach ($origins as $origin) {
    $response = NativePreviewTransport::relativeResponse($response, $origin);
}

$response->send();
$log($response->getStatusCode().($viaProxy ? ' jar' : ''));
$kernel->terminate($request, $response);
