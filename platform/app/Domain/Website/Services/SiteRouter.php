<?php

declare(strict_types=1);

namespace App\Domain\Website\Services;

use App\Domain\Website\Models\Page;
use App\Domain\Website\Models\Redirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Answers a request for a path no route owns (docs/09 §3): one of the 301s from the old website, or nothing.
 *
 * **A page is a file.** Since Woodex OS 1 (docs/audit/16.19) every public page is `public/<path>/index.html`,
 * which the web server answers before Laravel runs. This class used to render a page from its database row when
 * no file existed, which is how a second frontend came to live behind the same URL: a row and a file could
 * disagree, and the row won wherever the file was missing. The renderer, its Blade layout and its 20 section
 * views are gone (owner, 21 Oct 2026); a URL with no file is now a 404, like any other address nothing owns.
 *
 * Canonical URLs end in a slash; a request without one is redirected once, permanently.
 */
final class SiteRouter
{
    /**
     * First segments that belong to the app, never to a page: `q` is the client's quotation link (QUO-009) and
     * `i` the client's invoice link (INV-005) and `p` the client's project portal (PRJ-006), all signed routes
     * that must keep their 404s to themselves; `portal` is the code page.
     */
    private const array RESERVED = [
        'install', 'up', 'build', 'vendor', 'storage', 'api', 'webhooks', 'livewire', 'q', 'i', 'p', 'portal', 'admin',
    ];

    /**
     * The response for a path, or null to let the normal 404 page render. Only the old website's 301s answer; a
     * database row is not a page, because no visitor can reach one without a file.
     */
    public function respond(Request $request): ?SymfonyResponse
    {
        $path = '/'.ltrim((string) $request->path(), '/');
        $firstSegment = explode('/', trim($path, '/'))[0] ?? '';

        if ($this->isReserved($firstSegment) || $this->onAdminDomain($request)) {
            return null;
        }

        $slug = Page::normalizeSlug($path);

        // One canonical form: /about and /about/ are the same page, and only the second is linked to (docs/09 §11.1).
        if (! str_ends_with((string) $request->getPathInfo(), '/')) {
            $query = $request->getQueryString();

            return $this->redirect($slug.($query !== null ? '?'.$query : ''), $request);
        }

        $redirect = Redirect::query()->where('from_path', $slug)->first();

        if ($redirect !== null) {
            $redirect->increment('hits');

            return $this->redirect($redirect->to_path, $request, (int) $redirect->code);
        }

        return null;
    }

    /**
     * An absolute URL of our own making: redirect() and url() both trim the trailing slash, and the slash is the
     * whole point of the canonical URL (RedirectToInstaller builds its target the same way).
     */
    private function redirect(string $to, Request $request, int $code = 301): RedirectResponse
    {
        $target = $request->getSchemeAndHttpHost().$request->getBaseUrl().'/'.ltrim($to, '/');
        $response = new RedirectResponse($target, $code);
        $response->setCache(['no_store' => true]);

        return $response;
    }

    private function isReserved(string $segment): bool
    {
        if ($segment === '') {
            return false;
        }

        return in_array($segment, [...self::RESERVED, trim((string) config('woodex.admin.path'), '/')], true);
    }

    private function onAdminDomain(Request $request): bool
    {
        $domain = (string) config('woodex.admin.domain');

        return $domain !== '' && $request->getHost() === $domain;
    }
}
