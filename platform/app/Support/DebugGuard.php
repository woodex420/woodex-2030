<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Foundation\Application;

/**
 * A site that calls itself production never draws the debugging page, whatever APP_DEBUG says.
 *
 * That page shows the request, the database queries and the code around a fault to whoever caused the fault. The
 * example settings file switches it on, for a developer's machine; left on by mistake on the live site it would
 * be read by a stranger. So in production the switch is turned off here, and the fact that it was asked for is
 * kept for the health report, which tells the owner to correct the settings file (master list T-160).
 */
final class DebugGuard
{
    public static function apply(Application $app): void
    {
        if ($app->isProduction() && (bool) config('app.debug')) {
            config(['app.debug' => false, 'app.debug_was_asked' => true]);
        }
    }

    /** True when the settings asked for debugging on a production site and were refused. */
    public static function wasAsked(): bool
    {
        return (bool) config('app.debug_was_asked', false);
    }
}
