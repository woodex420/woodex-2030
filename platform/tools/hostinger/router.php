<?php

declare(strict_types=1);

/*
 * For a machine without Apache only (the sandbox). PHP's own server does not read .htaccess; this file answers as
 * the .htaccess of the website's folder does, so that the installer and the admin can be tried from a release
 * folder:
 *
 *   php -S 127.0.0.1:8195 -t <website folder> platform/tools/hostinger/router.php
 *
 * It is a stand-in, kept as short as the rules it copies. The check that counts serves the same folder with
 * Apache and the real .htaccess (workflow os-ci.yml, job "hostinger").
 */

$site = rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/');
$path = rawurldecode((string) (parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/'));

$refuse = static function (): bool {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';

    return true;
};

// Dotfiles, the application's own folders and the files beside them.
if (preg_match('#(^|/)\.#', $path) === 1
    || preg_match('#^/(?:platform|apps|api-source|deploy|docs|scripts|node_modules|vendor|storage|tests|resources|bootstrap|config|database)(?:/|$)#i', $path) === 1
    || preg_match('#^/(?:composer\.(?:json|lock)|package(?:-lock)?\.json|README\.md|AGENTS\.md|vercel\.json|RELEASE\.json|WOODEX-SETUP-CODE\.txt)$#i', $path) === 1) {
    return $refuse();
}

if ($path === '/') {
    header('Location: https://woodex-2030.vercel.app/', true, 302);

    return true;
}

// A file of Laravel's public folder is served as it is. Never a script; two addresses are always the application's.
$file = $site.'/platform/public'.$path;

if (preg_match('#^/[A-Za-z0-9_][A-Za-z0-9_./-]*$#', $path) === 1 && preg_match('#\.php$#i', $path) !== 1
    && ! in_array($path, ['/robots.txt', '/assets/theme.css'], true) && is_file($file)) {
    $types = [
        'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'mjs' => 'text/javascript; charset=utf-8',
        'json' => 'application/json', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp', 'ico' => 'image/x-icon', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf',
        'wav' => 'audio/wav', 'txt' => 'text/plain; charset=utf-8', 'md' => 'text/plain; charset=utf-8',
    ];
    header('Content-Type: '.($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: '.filesize($file));
    readfile($file);

    return true;
}

// Everything else is the application's, through the bridge file, as the web server would start it.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $site.'/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require $site.'/index.php';
