<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Modules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the routes of a switched-off module (config/modules.php).
 *
 * It is first in the middleware priority list (bootstrap/app.php), so the 404 is raised before a session
 * starts, a route model is looked up, a signature is checked or a handler runs.
 */
final class EnsureModuleIsEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless(Modules::enabled($module), 404);

        return $next($request);
    }
}
