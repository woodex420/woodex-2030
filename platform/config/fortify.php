<?php

use Laravel\Fortify\Features;

$adminPath = trim((string) env('ADMIN_PATH', 'admin'), '/');

return [

    /*
    | Authentication for the Woodex OS admin (staff only, no public registration).
    | Login lives under the admin domain/path: local http://localhost:8000/admin/login,
    | production https://admin.woodex.com.pk/login (see config/woodex.php).
    */

    'guard' => 'web',

    'passwords' => 'users',

    'username' => 'email',

    'email' => 'email',

    'lowercase_usernames' => true,

    'home' => '/'.$adminPath,

    'prefix' => $adminPath,

    'domain' => env('ADMIN_DOMAIN') ?: null,

    'middleware' => ['web'],

    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
        'passkeys' => 'passkeys',
    ],

    'views' => true,

    'passkeys' => [
        'relying_party_id' => parse_url((string) config('app.url'), PHP_URL_HOST),
        'allowed_origins' => [config('app.url')],
        'timeout' => 60000,
    ],

    'features' => [
        // Staff accounts are created by the owner/admin (invites), never self-registered.
        Features::resetPasswords(),
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]),
    ],

];
