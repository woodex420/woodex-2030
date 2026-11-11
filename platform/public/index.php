<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// A half-finished Hostinger setup explains itself instead of showing a blank "500" page (docs/06 §2).
if (PHP_VERSION_ID < 80400 || ! is_file(__DIR__.'/../vendor/autoload.php')) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 300');
    $problem = PHP_VERSION_ID < 80400
        ? ['This site runs PHP '.PHP_VERSION.'. Woodex OS needs PHP 8.4.', 'In hPanel open Websites → this site → Advanced → PHP Configuration, choose 8.4 and save. Then redeploy (Advanced → Git → Redeploy) so the packages are installed for PHP 8.4.']
        : ['The PHP packages (Composer "vendor" folder) are not installed yet.', 'Hostinger installs them during the Git deployment from composer.json. Open hPanel → Advanced → Git → Deployments and read the log of the last deployment. With SSH you can also run: cd platform && composer install --no-dev --optimize-autoloader'];
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Setup needed · Woodex OS</title></head>'
        .'<body style="margin:0;font:15px/1.6 system-ui,sans-serif;background:#f5f6f8;color:#1a2a45"><div style="max-width:620px;margin:10vh auto;padding:32px;background:#fff;border-radius:16px;border:1px solid #e3e9f1">'
        .'<p style="margin:0 0 4px;font-size:12px;letter-spacing:.12em;color:#6e87ac">WOODEX OS · SETUP</p><h1 style="margin:0 0 12px;font-size:22px;font-weight:600">'.htmlspecialchars($problem[0]).'</h1>'
        .'<p style="margin:0">'.htmlspecialchars($problem[1]).'</p><p style="margin:16px 0 0;font-size:13px;color:#6e87ac">Guide: docs/06-DEPLOYMENT-HOSTINGER.md, section 2, in the repository.</p></div></body></html>';
    exit;
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
