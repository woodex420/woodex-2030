<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Install\Installer;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A freshly deployed site has no .env yet: every page then opens the installer instead of a 500 error (D-58).
 * The same while step 2 of the installer has not finished (the setup code is still waiting).
 */
final class RedirectToInstaller
{
    public function __construct(private readonly Installer $installer) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('install', 'install/*', 'up')) {
            return $next($request);
        }

        if (! $this->installer->site()->isConfigured() || $this->installer->hasCode()) {
            // A plain redirect: the redirector would reach for the session store, which needs the database.
            return new RedirectResponse($request->getSchemeAndHttpHost().$request->getBaseUrl().'/install', 302);
        }

        return $next($request);
    }
}
