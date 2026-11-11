<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * Which optional modules are switched on (config/modules.php).
 *
 * Everything here reads configuration at call time, so one answer governs the route middleware, the menu,
 * the search and the scheduler. An unknown key is always off: a typing mistake closes a module, never opens one.
 */
final class Modules
{
    /** @return list<string> */
    public static function available(): array
    {
        return array_keys((array) config('modules.available', []));
    }

    /** @return list<string> */
    public static function enabledKeys(): array
    {
        $enabled = config('modules.enabled', []);

        if (is_string($enabled)) {
            $enabled = trim($enabled) === '*'
                ? self::available()
                : array_map(trim(...), explode(',', $enabled));
        }

        return array_values(array_intersect(self::available(), (array) $enabled));
    }

    public static function enabled(string $module): bool
    {
        return in_array($module, self::enabledKeys(), true);
    }

    /**
     * The module keys a named route is gated by, read from its own middleware.
     *
     * @return list<string>
     */
    public static function forRoute(?string $name): array
    {
        $route = $name === null ? null : Route::getRoutes()->getByName($name);
        if ($route === null) {
            return [];
        }

        $modules = [];
        foreach ($route->middleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'module:')) {
                $modules[] = substr($middleware, 7);
            }
        }

        return $modules;
    }

    /** False when the route belongs to a module that is switched off. Routes without a module are always open. */
    public static function routeIsOpen(?string $name): bool
    {
        foreach (self::forRoute($name) as $module) {
            if (! self::enabled($module)) {
                return false;
            }
        }

        return true;
    }
}
