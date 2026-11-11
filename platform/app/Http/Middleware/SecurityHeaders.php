<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Website\ContactFormResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers (NFR-005). The admin is never indexed by search engines.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $frameOptions = (string) config('woodex.security.frame_options', 'SAMEORIGIN');

        if ($frameOptions !== '') {
            $response->headers->set('X-Frame-Options', $frameOptions);
        }

        // The page builder's live preview frames a public page inside the admin. In production the
        // admin sits on its own domain (admin.woodex.com.pk) while the site is woodex.com.pk, so
        // SAMEORIGIN would blank the preview exactly where the owner uses it. A staff editor's
        // ?preview=1 response may be framed by the admin and nothing else; visitors get the header
        // as before, and the preview response is never cached (SiteRouter).
        $previewing = $request->boolean('preview')
            && $request->user() !== null
            && $request->user()->can('website.edit');

        if ($previewing) {
            $response->headers->remove('X-Frame-Options');

            $ancestors = ["'self'"];
            $adminDomain = (string) config('woodex.admin.domain');

            if ($adminDomain !== '') {
                $ancestors[] = $request->getScheme().'://'.$adminDomain;
            }

            $response->headers->set('Content-Security-Policy', 'frame-ancestors '.implode(' ', $ancestors));
        }

        // Staff previews frame our own PDFs inside the admin (the document preview modal). A PDF has no
        // scripts to protect; let the admin frame it while every HTML page keeps SAMEORIGIN.
        if (str_starts_with((string) $response->headers->get('Content-Type'), 'application/pdf')) {
            $response->headers->remove('X-Frame-Options');

            $ancestors = ["'self'"];
            $adminDomain = (string) config('woodex.admin.domain');

            if ($adminDomain !== '') {
                $ancestors[] = $request->getScheme().'://'.$adminDomain;
            }

            foreach ((array) config('woodex.security.pdf_frame_ancestors', []) as $extra) {
                if (is_string($extra) && $extra !== '') {
                    $ancestors[] = $extra;
                }
            }

            $response->headers->set('Content-Security-Policy', 'frame-ancestors '.implode(' ', $ancestors));
        }

        // OAuth callbacks carry short-lived authorization codes in the query string. Never forward them as Referer.
        $response->headers->set('Referrer-Policy', $request->routeIs('google.*') ? 'no-referrer' : 'strict-origin-when-cross-origin');
        // Auth pages can include reset tokens and opted-in synthetic demo credentials: never cache them.
        if ($request->routeIs('google.*', 'login', 'login.store', 'password.*', 'two-factor.*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        if (app()->isProduction() && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if ($this->isAdminRequest($request)) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        // The normal header policy must not overwrite the stricter native Contact response policy.
        if (ContactFormResponse::wantsHtml($request)) {
            ContactFormResponse::protect($response);
        }

        return $response;
    }

    private function isAdminRequest(Request $request): bool
    {
        $domain = config('woodex.admin.domain');

        if (is_string($domain) && $domain !== '' && $request->getHost() === $domain) {
            return true;
        }

        $path = (string) config('woodex.admin.path');

        return $path !== '' && ($request->is($path) || $request->is($path.'/*'));
    }
}
