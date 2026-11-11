<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Settings\SiteSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The tracking IDs from Website → Settings, as one script every page loads (docs/09 §11.3, D-69).
 *
 * The site's pages are files the web server answers before Laravel runs, so a switch in the admin cannot change
 * them. This route is the one thing the files can share: each page carries `<script src="/site/tags.js" defer>`,
 * and this answers with GA4, Google Tag Manager and Clarity — or with nothing at all when no ID is set.
 *
 * Two rules from the renderer it replaces still hold: **nothing loads until the owner pastes an ID**, and
 * **nothing loads for signed-in staff**, whose visits are not visitors.
 *
 * Search Console verification is the one setting that cannot travel this way: Google's verifier does not run
 * JavaScript, so the meta tag has to be in the HTML or replaced by a DNS record. The screen says so.
 */
final class SiteTagsController extends Controller
{
    public function __invoke(Request $request, SiteSettings $site): Response
    {
        $on = (bool) $site->tracking_enabled && $request->user() === null;

        return response()
            ->view('site.tags', [
                'ga4' => $on ? trim((string) $site->ga4_id) : '',
                'gtm' => $on ? trim((string) $site->gtm_id) : '',
                'clarity' => $on ? trim((string) $site->clarity_id) : '',
            ])
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            // A saved ID must be live on the next page load, not in five minutes.
            ->header('Cache-Control', 'no-store, max-age=0');
    }
}
