<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * A hub page is several screens in one. The page opens when the person may open **any** of its tabs and
 * each tab keeps its own permission; before this, a hub route was reachable by any signed-in colleague,
 * who then landed on a page whose every tab their role could not use (deep QA 21 Oct, slice G).
 *
 * Usage: `->middleware('can-any:users.manage,settings.manage')`.
 */
final class EnsureAnyPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        foreach ($permissions as $permission) {
            if (Gate::allows($permission)) {
                return $next($request);
            }
        }

        abort(403);
    }
}
