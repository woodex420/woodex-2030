<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * The headers every answer of the admin carries (App\Http\Middleware\SecurityHeaders), read the way a browser
 * gets them. Found missing a test by the analysis of the hosted demo: the server named its software and version
 * ("X-Powered-By: PHP/8.5.12") and nothing would have noticed.
 */

it('sends the baseline headers on the sign-in page and keeps the page out of every cache', function () {
    $response = $this->get('/admin/login')->assertOk();

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
    $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

it('sends them with a refusal too: the admin\'s own 404 page', function () {
    $response = $this->get('/admin/no-such-screen')->assertNotFound();

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('never names the software that runs the admin', function () {
    // A header set by anything behind the middleware (a package, a controller) is taken off again. The one PHP adds
    // itself (expose_php) is removed with header_remove(), which a test cannot observe: the Vercel rehearsal does
    // (tools/vercel/check-entry.sh).
    Route::get('/admin/_test/named-software', fn () => response('ok')->header('X-Powered-By', 'PHP/8.5.12'));

    $this->get('/admin/_test/named-software')->assertOk()->assertHeaderMissing('X-Powered-By');
    $this->get('/admin/login')->assertOk()->assertHeaderMissing('X-Powered-By')->assertHeaderMissing('Server');
});

it('asks browsers for https only where the site is really served over https in production', function () {
    $this->get('/admin/login')->assertOk()->assertHeaderMissing('Strict-Transport-Security');

    app()->detectEnvironment(fn () => 'production');

    $this->get('https://localhost/admin/login')->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    $this->get('http://localhost/admin/login')->assertHeaderMissing('Strict-Transport-Security');
});
