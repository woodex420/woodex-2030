<?php

declare(strict_types=1);

namespace App\Support\Install;

use InvalidArgumentException;
use RuntimeException;

/**
 * Writes .env files the way phpdotenv reads them back (tested round trip): bare values when they are plain, otherwise
 * double quotes with \, " and $ escaped, so a database password like a$b"c is stored literally.
 */
final class EnvFile
{
    /**
     * @param  array<string, array<string, string|int|bool|null>>  $sections  comment => [KEY => value]
     */
    public static function render(array $sections): string
    {
        $lines = [
            '# Woodex OS settings for this site. Written by the installer; edit with care and never share or commit it.',
            '# Reference: docs/06-DEPLOYMENT-HOSTINGER.md section 4.',
        ];

        foreach ($sections as $comment => $values) {
            $lines[] = '';
            $lines[] = '# '.$comment;

            foreach ($values as $key => $value) {
                $lines[] = $key.'='.self::quote($value);
            }
        }

        return implode("\n", $lines)."\n";
    }

    public static function quote(string|int|bool|null $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $value = (string) $value;

        if (str_contains($value, "\n") || str_contains($value, "\r") || str_contains($value, "\0")) {
            throw new InvalidArgumentException('Settings cannot contain line breaks.');
        }

        if (preg_match('#^[A-Za-z0-9_./:@,+\-]*$#', $value) === 1) {
            return $value;
        }

        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }

    /** Atomic write (temporary file + rename) readable by the owner only. */
    public static function write(string $path, string $contents): void
    {
        $temporary = $path.'.tmp-'.bin2hex(random_bytes(4));

        if (@file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write '.basename($path).': the folder is not writable.');
        }

        @chmod($temporary, 0600);

        if (! @rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException('Cannot save '.basename($path).'.');
        }
    }

    /**
     * Removes keys (and nothing else) from an existing file.
     *
     * @param  list<string>  $keys
     */
    public static function forget(string $path, array $keys): void
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return;
        }

        $kept = array_filter(
            explode("\n", $contents),
            fn (string $line): bool => ! in_array(strstr($line, '=', true), $keys, true),
        );

        self::write($path, implode("\n", $kept));
    }
}
