<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Support\Api\ApiErrors;
use App\Support\Modules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Whatever no address under /api/v1 owns, for every method: the last route of that group (routes/api.php).
 *
 * Without it such a request went to the website's catch-all, which starts a session: an API that sets a cookie,
 * and whose "not found" was whatever the website module made of it. Here:
 *
 *   - an address that exists but does not take this method answers 405 with the methods it does take (Allow);
 *   - an address of a part that is switched off answers 404 to every method, so that it cannot be told from one
 *     that never existed;
 *   - everything else answers 404.
 */
final class UnknownAddress
{
    public const string ROUTE = 'api.v1.unknown';

    public function __invoke(Request $request): Response
    {
        $takes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->isFallback || $route->getName() === self::ROUTE || ! $route->matches($request, false)) {
                continue;
            }

            if (! Modules::routeIsOpen($route->getName())) {
                return ApiErrors::answer('NOT_FOUND', 404);
            }

            $takes = [...$takes, ...array_diff($route->methods(), ['HEAD'])];
        }

        if ($takes === []) {
            return ApiErrors::answer('NOT_FOUND', 404);
        }

        $allow = implode(', ', array_values(array_unique($takes)));

        // A browser's question before a request (OPTIONS) that the cross-origin rules did not answer themselves:
        // it is told what the address takes, and nothing about any other website.
        if ($request->isMethod('OPTIONS')) {
            return response()->noContent(204, ['Allow' => $allow, 'Cache-Control' => 'no-store']);
        }

        return ApiErrors::answer('METHOD_NOT_ALLOWED', 405, [], ['Allow' => $allow]);
    }
}
