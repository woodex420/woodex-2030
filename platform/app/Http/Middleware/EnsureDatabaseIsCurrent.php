<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Install\DatabaseUpdates;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After a deploy with new migrations, admin pages would fail on missing tables. Instead the owner (or an admin with
 * system.health) is sent to the one-click update page, and everyone else sees a short "updating" notice (D-58).
 */
final class EnsureDatabaseIsCurrent
{
    public function __construct(private readonly DatabaseUpdates $updates) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->updates->isCurrent()) {
            return $next($request);
        }

        if ($request->user()?->can('system.health')) {
            return redirect()->route('admin.system.updates');
        }

        return response()->view('errors.updating', [], 503)->header('Retry-After', '120');
    }
}
