<?php

declare(strict_types=1);

namespace App\Support\Install;

/**
 * Laravel's own lists of the packages it loads, kept honest across a deployment.
 *
 * Laravel writes down once which packages it loads (bootstrap/cache/packages.php and services.php) and reads that
 * on every request afterwards. Composer normally has the lists written again whenever the packages change.
 * A release for a host that takes it by Git already holds its packages, and nothing there is relied on to run
 * Composer: when such a release is copied over the one before, an old list can stay where it is. A list that names
 * a package the new release no longer has stops the application before it can show anything, the page that
 * applies an update included.
 *
 * So a list that is older than the packages is dropped when the application starts, and Laravel writes a new one
 * on that same request.
 */
final class PackageLists
{
    /**
     * @param  string  $installed  the file Composer rewrites whenever the packages change (vendor/composer/installed.json)
     * @param  list<string>  $lists  the files Laravel keeps
     * @return list<string> the lists that were dropped
     */
    public static function dropWhenStale(string $installed, array $lists): array
    {
        $changed = is_file($installed) ? filemtime($installed) : false;

        if ($changed === false) {
            return [];
        }

        $dropped = [];

        foreach ($lists as $list) {
            $written = is_file($list) ? filemtime($list) : false;

            if ($written !== false && $written < $changed && @unlink($list)) {
                $dropped[] = $list;
            }
        }

        return $dropped;
    }
}
