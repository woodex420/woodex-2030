<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ContentPolicy;
use App\Support\Modules;
use App\Support\Website\ContactFormResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers (NFR-005). The admin is never indexed by search engines.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // The number that marks this answer's own scripts (T-068). It is made before the page is drawn, so that
        // the page, Vite and Livewire can write it on their script tags; the policy that names it is added below.
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        // PHP adds "X-Powered-By: PHP/<version>" itself when expose_php is on (the default of most hosts). A
        // version number helps only someone looking for a known weakness.
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }
        $response->headers->remove('X-Powered-By');

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

        $this->contentPolicy($request, $response, $nonce, $previewing ? '' : $frameOptions);

        // The normal header policy must not overwrite the stricter native Contact response policy.
        if (ContactFormResponse::wantsHtml($request)) {
            ContactFormResponse::protect($response);
        }

        return $response;
    }

    /**
     * Content-Security-Policy for every page the product draws (App\Support\ContentPolicy).
     *
     * Only pages: a PDF, a download or a JSON answer has nothing a browser would run. An answer that already
     * carries a policy keeps it (a staff preview of a website page, framed by the admin). And the pages of the
     * public website module are left alone: that module is switched off in the product, and where it is on its
     * pages load an owner's own tags (analytics, tag manager) from other servers.
     */
    private function contentPolicy(Request $request, Response $response, string $nonce, string $frameOptions): void
    {
        $header = ContentPolicy::headerName();

        if ($header === null
            || ! str_starts_with(strtolower((string) $response->headers->get('Content-Type')), 'text/html')
            || $response->headers->has('Content-Security-Policy')
            || ! $this->drawnByTheProduct($request)) {
            return;
        }

        $response->headers->set($header, ContentPolicy::for($nonce, $frameOptions));
    }

    /** Everything except the public website module's own pages. */
    private function drawnByTheProduct(Request $request): bool
    {
        if (! Modules::enabled('website')) {
            return true;
        }

        // Where the module is on: not the old page builder and studio inside the admin. They are part of that
        // module, whole applications of their own, and have not been read against the policy (T-115).
        if ($request->routeIs('admin.website.builder*', 'admin.website.studio*')) {
            return false;
        }

        if ($this->isAdminRequest($request)) {
            return true;
        }

        return $request->routeIs(
            'login', 'login.*', 'logout', 'password.*', 'two-factor.*', 'verification.*', 'passkey*',
            'quotes.portal*', 'invoices.portal*', 'projects.portal*', 'portal', 'portal.*', 'install*',
        );
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
