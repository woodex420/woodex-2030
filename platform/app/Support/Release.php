<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Which release this installation runs.
 *
 * A release that is put together for a host (tools/hostinger/build-release.sh) carries a file RELEASE.json in the
 * website's folder, beside platform/: the commit it was made from and when. Vercel names the commit in a server
 * variable. Anywhere else (a working copy, CI) there is no release to name.
 *
 * Shown on System → Health, so that after a deployment the owner can read which code is live.
 */
final class Release
{
    public const string FILE = 'RELEASE.json';

    public const string NONE = 'not a packaged release';

    /**
     * @return array{commit: string, built_at: ?CarbonImmutable, uncommitted: bool, source: string}|null
     */
    public static function read(string $basePath, ?string $vercelCommit = null): ?array
    {
        $file = dirname(rtrim($basePath, '/')).'/'.self::FILE;

        if (is_file($file)) {
            $data = json_decode((string) @file_get_contents($file), true);
            $commit = is_array($data) && is_string($data['commit'] ?? null) ? $data['commit'] : '';

            if (preg_match('/^[0-9a-f]{7,40}$/', $commit) === 1) {
                try {
                    $builtAt = is_string($data['built_at'] ?? null) ? CarbonImmutable::parse($data['built_at']) : null;
                } catch (Throwable) {
                    $builtAt = null;
                }

                return ['commit' => $commit, 'built_at' => $builtAt, 'uncommitted' => ($data['uncommitted_changes'] ?? false) === true, 'source' => 'release'];
            }
        }

        if (is_string($vercelCommit) && preg_match('/^[0-9a-f]{7,40}$/', $vercelCommit) === 1) {
            return ['commit' => $vercelCommit, 'built_at' => null, 'uncommitted' => false, 'source' => 'vercel'];
        }

        return null;
    }

    /** One line for a person: "commit 35c8a2c, put together 18 Nov 2026, 08:49". */
    public static function describe(string $basePath, ?string $vercelCommit = null, string $timezone = 'UTC'): string
    {
        $release = self::read($basePath, $vercelCommit);

        if ($release === null) {
            return self::NONE;
        }

        $line = 'commit '.substr($release['commit'], 0, 7);

        if ($release['built_at'] !== null) {
            $line .= ', put together '.$release['built_at']->setTimezone($timezone)->format('j M Y, H:i');
        }

        if ($release['source'] === 'vercel') {
            $line .= ' (built by Vercel)';
        }

        return $release['uncommitted'] ? $line.' — with changes that were not committed' : $line;
    }
}
